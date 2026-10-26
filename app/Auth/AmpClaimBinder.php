<?php

namespace App\Auth;

use App\Enums\WorkItemState;
use App\Machine\TeamMemberships;
use App\Models\AmpIdentity;
use App\Models\WorkItem;
use App\Work\AmpSessionReference;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class AmpClaimBinder
{
    public function __construct(private TeamMemberships $memberships) {}

    public function bind(AmpWorkloadIdentity $identity): AmpClaimContext
    {
        try {
            return DB::transaction(fn (): AmpClaimContext => $this->resolve($identity, true), 5);
        } catch (UniqueConstraintViolationException) {
            return DB::transaction(fn (): AmpClaimContext => $this->resolve($identity, false), 5);
        }
    }

    public function assertCurrent(AmpClaimContext $context, WorkItem $item): void
    {
        if ($item->id !== $context->itemId) {
            $this->deny('current_item');
        }
        if ($item->fence !== $context->fence) {
            $this->deny('current_fence');
        }
        if ($item->launch_nonce === null || ! hash_equals($item->launch_nonce, $context->launchNonce)) {
            $this->deny('launch_nonce');
        }

        $matches = $this->matchingItems($context->identity);
        if ($matches->count() !== 1) {
            $this->deny('item_match_count');
        }
        if ($matches->sole()->id !== $item->id) {
            $this->deny('current_item_match');
        }

        $link = AmpIdentity::query()->where('amp_user_id', $context->identity->userId)->first();
        $user = User::query()->find($context->userId);
        if (! $link instanceof AmpIdentity) {
            $this->deny('identity_link_missing');
        }
        if ($link->user_id !== $context->userId) {
            $this->deny('identity_link_mismatch');
        }
        if (! $user instanceof User) {
            $this->deny('user_missing');
        }
        if (! $this->admitted($user)) {
            $this->deny('user_admission');
        }
        if ((string) $user->getKey() !== $item->claimant_actor) {
            $this->deny('claimant_actor');
        }
        if (! $this->memberships->belongsTo($user, $item->workTask->team_id)) {
            $this->deny('team_membership');
        }
    }

    private function resolve(AmpWorkloadIdentity $identity, bool $mayCreate): AmpClaimContext
    {
        $matches = $this->matchingItems($identity, true);
        if ($matches->count() !== 1) {
            $this->deny('item_match_count');
        }
        $item = $matches->sole();

        $link = AmpIdentity::query()->where('amp_user_id', $identity->userId)->lockForUpdate()->first();
        if ($link instanceof AmpIdentity) {
            $user = User::query()->whereKey($link->user_id)->lockForUpdate()->first();
        } else {
            if (! $mayCreate) {
                $this->deny('identity_creation_race');
            }
            $users = User::query()
                ->where('normalized_email', mb_strtolower(trim($identity->email)))
                ->where('status', 'active')
                ->whereNotNull('email_verified_at')
                ->lockForUpdate()
                ->limit(2)
                ->get();
            if ($users->count() !== 1) {
                $this->deny('eligible_user_count');
            }
            $user = $users->sole();
            if ((string) $user->getKey() !== $item->claimant_actor) {
                $this->deny('claimant_actor');
            }
            $link = AmpIdentity::query()->create([
                'amp_user_id' => $identity->userId,
                'user_id' => $user->getKey(),
            ]);
        }

        if (! $user instanceof User) {
            $this->deny('user_missing');
        }
        if (! $this->admitted($user)) {
            $this->deny('user_admission');
        }
        if ((string) $user->getKey() !== $item->claimant_actor) {
            $this->deny('claimant_actor');
        }
        if (! $this->memberships->belongsTo($user, $item->workTask->team_id)) {
            $this->deny('team_membership');
        }
        if ($item->launch_nonce === null) {
            $this->deny('launch_nonce');
        }

        return new AmpClaimContext($identity, $item->id, $item->fence, $item->launch_nonce, (int) $link->user_id);
    }

    /** @return Collection<int, WorkItem> */
    private function matchingItems(AmpWorkloadIdentity $identity, bool $lock = false): Collection
    {
        $query = WorkItem::query()
            ->with(['workTask', 'claimantSpoke'])
            ->where('amp_project_id', $identity->projectId)
            ->where('amp_thread_id', $identity->threadId)
            ->whereIn('state', [WorkItemState::Claimed, WorkItemState::Delivered, WorkItemState::Routed, WorkItemState::AwaitingVerification])
            ->whereNotNull('handed_off_at')
            ->whereNotNull('handed_off_session_ref')
            ->whereNotNull('launch_nonce')
            ->whereNotNull('claimant_actor')
            ->whereNotNull('claimant_spoke_id')
            ->where('lease_until', '>', now())
            ->whereHas('workTask', fn ($query) => $query->whereNull('cancelled_at'))
            ->whereHas('claimantSpoke', fn ($query) => $query->whereNull('retired_at')->whereNull('orphaned_at'));
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->filter(function (WorkItem $item) use ($identity): bool {
            if ($item->claimantSpoke === null || $item->claimantSpoke->actor_id !== $item->claimant_actor) {
                return false;
            }
            try {
                $reference = AmpSessionReference::parse((string) $item->handed_off_session_ref);
            } catch (InvalidArgumentException) {
                return false;
            }

            return hash_equals($reference->projectId, $identity->projectId)
                && hash_equals($reference->threadId, $identity->threadId);
        })->values();
    }

    private function admitted(User $user): bool
    {
        return $user->status === 'active' && $user->email_verified_at !== null
            && ! OffboardedSubject::userIsOffboarded((string) $user->getKey());
    }

    private function deny(string $reason): never
    {
        try {
            Log::warning('Orb authentication denied.', ['component' => 'binder', 'reason_code' => $reason]);
        } catch (Throwable) {
            // Diagnostics must never replace the authentication denial.
        }

        throw new AuthorizationException('Unauthenticated.');
    }
}
