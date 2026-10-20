<?php

namespace App\Postmaster;

use App\Auth\CapstanCredentialDeclaration;
use App\Models\MachineTeamWorkSetting;
use App\Models\Spoke;
use App\Models\WorkItem;
use App\Models\WorkOrchestratorSeat;
use App\Support\BoundCredentialSpokeResolver;
use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\AuditReason;
use ArtisanBuild\BuiltForCloud\BoundBearerCredential;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialProtocolBinding;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\LifecycleEventType;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;

final readonly class ReplaceSpokeCredential
{
    private const int SQLITE_LOCK_ATTEMPTS = 50;

    public function __construct(
        private BoundCredentialSpokeResolver $spokes,
        private RevokeCredential $revokeCredential,
    ) {}

    /** @return array{spoke: Spoke|null, replaced: bool, conflict: bool} */
    public function handle(
        string $actorId,
        string $newCredentialId,
        ?BoundBearerCredential $authenticatedOldCredential,
        CarbonImmutable $now,
        ?string $previousToken = null,
        string $currentAppPurpose = CapstanCredentialDeclaration::MACHINE,
    ): array {
        return $this->retrySqliteBusy(fn (): array => DB::transaction(function () use ($actorId, $newCredentialId, $authenticatedOldCredential, $now, $previousToken, $currentAppPurpose): array {
            $alreadyBound = Spoke::query()
                ->where('credential_id', $newCredentialId)
                ->where('actor_id', $actorId)
                ->lockForUpdate()
                ->first();

            if ($alreadyBound !== null) {
                if ($alreadyBound->retired_at !== null) {
                    throw new RuntimeException('This Postmaster spoke has been retired.');
                }

                return ['spoke' => $alreadyBound, 'replaced' => false, 'conflict' => false];
            }

            if ($authenticatedOldCredential === null
                || $authenticatedOldCredential->userId === null
                || ! hash_equals($actorId, $authenticatedOldCredential->userId)
                || hash_equals($newCredentialId, $authenticatedOldCredential->id)) {
                $transferred = $this->transferOrphaned(
                    $actorId,
                    $newCredentialId,
                    $previousToken,
                    $currentAppPurpose,
                    $now,
                );

                if ($transferred !== null) {
                    return $transferred;
                }

                return $this->fresh($actorId, $newCredentialId, $now);
            }

            $spoke = Spoke::query()
                ->where('credential_id', $authenticatedOldCredential->id)
                ->lockForUpdate()
                ->first();

            if ($spoke === null) {
                $oldCredential = Credential::query()->whereKey($authenticatedOldCredential->id)->lockForUpdate()->first();

                if ($oldCredential === null || $oldCredential->revoked_at !== null
                    || ($oldCredential->expires_at !== null && ! $oldCredential->expires_at->isAfter($now))) {
                    ($this->revokeCredential)($newCredentialId, AuditActor::boundUser($actorId));

                    return ['spoke' => null, 'replaced' => false, 'conflict' => true];
                }

                return $this->fresh($actorId, $newCredentialId, $now);
            }

            if ($spoke->retired_at !== null || ! hash_equals($actorId, $spoke->actor_id)) {
                return $this->fresh($actorId, $newCredentialId, $now);
            }

            $spoke->forceFill([
                'credential_id' => $newCredentialId,
                'updated_at' => $now,
            ])->save();
            ($this->revokeCredential)(
                $authenticatedOldCredential->id,
                AuditActor::boundUser($actorId),
            );

            return ['spoke' => $spoke->refresh(), 'replaced' => true, 'conflict' => false];
        }));
    }

    /** @return array{spoke: Spoke, replaced: false, conflict: false} */
    private function fresh(string $actorId, string $credentialId, CarbonImmutable $now): array
    {
        return [
            'spoke' => $this->spokes->resolve($actorId, $credentialId, $now),
            'replaced' => false,
            'conflict' => false,
        ];
    }

    /** @return array{spoke: Spoke, replaced: true, conflict: false}|null */
    private function transferOrphaned(
        string $actorId,
        string $newCredentialId,
        ?string $previousToken,
        string $currentAppPurpose,
        CarbonImmutable $now,
    ): ?array {
        if ($currentAppPurpose !== CapstanCredentialDeclaration::MACHINE
            || $previousToken === null
            || $previousToken === ''
            || strlen($previousToken) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $previousToken) === 1) {
            return null;
        }

        $oldCredential = Credential::query()
            ->where('secret_hash', hash('sha256', $previousToken))
            ->where('kind', CredentialKind::Bearer->value)
            ->lockForUpdate()
            ->first();

        if ($oldCredential === null
            || $oldCredential->status !== CredentialStatus::Active
            || $oldCredential->revoked_at === null
            || ($oldCredential->expires_at !== null && ! $oldCredential->expires_at->isAfter($now))
            || ! $this->hasOffboardingRevocation($oldCredential)
            || ! CredentialProtocolBinding::query()
                ->whereKey($oldCredential->id)
                ->where('app_purpose', CapstanCredentialDeclaration::MACHINE)
                ->exists()) {
            return null;
        }

        $spoke = Spoke::query()
            ->where('credential_id', $oldCredential->id)
            ->lockForUpdate()
            ->first();

        if ($spoke === null
            || $spoke->orphaned_at === null
            || $spoke->retired_at !== null
            || ! MachineTeamWorkSetting::query()->where('spoke_id', $spoke->id)->exists()
            || ! $this->activeApprover($actorId)
            || $this->hasLiveAuthority($spoke, $now)) {
            return null;
        }

        $spoke->forceFill([
            'actor_id' => $actorId,
            'credential_id' => $newCredentialId,
            'orphaned_at' => null,
            'updated_at' => $now,
        ])->save();
        ($this->revokeCredential)($oldCredential->id, AuditActor::boundUser($actorId));

        return ['spoke' => $spoke->refresh(), 'replaced' => true, 'conflict' => false];
    }

    private function hasOffboardingRevocation(Credential $credential): bool
    {
        return CredentialAuditEvent::query()
            ->where('credential_id', $credential->id)
            ->where('event', LifecycleEventType::Revoked->value)
            ->where('reason_code', AuditReason::Offboarding->value)
            ->where('occurred_at', $credential->revoked_at)
            ->exists();
    }

    private function activeApprover(string $actorId): bool
    {
        $user = User::query()->find($actorId);

        return $user instanceof User
            && $user->status === 'active'
            && RolePolicy::canUseProduct($user->role)
            && ! OffboardedSubject::query()
                ->where('user_id', $actorId)
                ->orWhere(function ($query) use ($actorId): void {
                    $query->where('subject_ref', 'capstan-user:'.$actorId)
                        ->where('user_id', OffboardedSubject::SUBJECT_ROW);
                })
                ->exists();
    }

    private function hasLiveAuthority(Spoke $spoke, CarbonImmutable $now): bool
    {
        return WorkItem::query()
            ->where('claimant_spoke_id', $spoke->id)
            ->whereIn('state', ['claimed', 'delivered', 'routed'])
            ->where('lease_until', '>', $now)
            ->whereHas('workTask', fn ($query) => $query->whereNull('cancelled_at'))
            ->exists()
            || WorkOrchestratorSeat::query()
                ->where('holder_spoke_id', $spoke->id)
                ->whereNotNull('holder_actor')
                ->whereNull('stopped_at')
                ->where('lease_until', '>', $now)
                ->exists();
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    private function retrySqliteBusy(\Closure $operation): mixed
    {
        $attempt = 0;

        beginning:
        try {
            return $operation();
        } catch (QueryException $exception) {
            $attempt++;

            if ($attempt < self::SQLITE_LOCK_ATTEMPTS
                && DB::getDriverName() === 'sqlite'
                && str_contains(strtolower($exception->getMessage()), 'database is locked')) {
                Sleep::usleep(random_int(1_000, 20_000));
                goto beginning;
            }

            throw $exception;
        }
    }
}
