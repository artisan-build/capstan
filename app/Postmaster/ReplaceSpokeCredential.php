<?php

namespace App\Postmaster;

use App\Models\Spoke;
use App\Support\BoundCredentialSpokeResolver;
use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\BoundBearerCredential;
use ArtisanBuild\BuiltForCloud\Credential;
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
    ): array {
        return $this->retrySqliteBusy(fn (): array => DB::transaction(function () use ($actorId, $newCredentialId, $authenticatedOldCredential, $now): array {
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
