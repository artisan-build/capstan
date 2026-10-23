<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Models\WorkDirective;
use App\Models\WorkItem;
use App\Models\WorkTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WorkDirectiveService
{
    private const array REPAIRS = ['send_enter', 'resend_pointer', 'respawn', 'respawn'];

    public function __construct(private WorkWorkerLinks $links, private WorkSeatService $seats) {}

    /**
     * @param  list<array<string, mixed>>  $heldItems
     * @return list<array<string, mixed>>
     */
    public function poll(int $teamId, string $actorId, int $spokeId, array $heldItems, CarbonImmutable $now): array
    {
        $directives = [];
        foreach ($heldItems as $held) {
            $item = WorkItem::query()->whereKey($held['item_id'])
                ->where('claimant_spoke_id', $spokeId)
                ->where('claimant_actor', $actorId)
                ->where('fence', $held['fence'])
                ->whereIn('state', [WorkItemState::Claimed->value, WorkItemState::Delivered->value, WorkItemState::Routed->value])
                ->whereHas('workTask', fn ($query) => $query->where('team_id', $teamId)->whereNull('cancelled_at'))
                ->with('workTask')
                ->first();
            if (! $item instanceof WorkItem) {
                continue;
            }

            $this->recordOutcome($item, $held, $now);
            $directive = $this->next($item->refresh(), $now);
            if ($directive instanceof WorkDirective) {
                $directives[] = $this->representation($directive);
            }
        }

        return $directives;
    }

    /** @param array<string, mixed> $held */
    private function recordOutcome(WorkItem $item, array $held, CarbonImmutable $now): void
    {
        if (! isset($held['directive_id'], $held['directive_outcome'])) {
            return;
        }

        DB::transaction(function () use ($item, $held, $now): void {
            $locked = WorkItem::query()->whereKey($item->id)->where('fence', $item->fence)
                ->where('claimant_spoke_id', $item->claimant_spoke_id)->lockForUpdate()->first();
            if (! $locked instanceof WorkItem) {
                return;
            }
            $directive = WorkDirective::query()->whereKey($held['directive_id'])
                ->where('work_item_id', $locked->id)
                ->where('claimant_spoke_id', $locked->claimant_spoke_id)
                ->where('fence', $locked->fence)
                ->lockForUpdate()
                ->first();
            if (! $directive instanceof WorkDirective) {
                return;
            }

            $directiveResult = $directive->outcome['result'] ?? null;
            $settledByFetch = $directive->status === 'succeeded'
                && in_array($directiveResult, ['succeeded', 'resolved_by_fetch'], true)
                && (($directive->outcome['receipt'] ?? false) === true || $directiveResult === 'resolved_by_fetch');
            if ($directive->status !== 'pending'
                && ! ($settledByFetch && $directive->kind === 'respawn' && $held['directive_outcome'] === 'succeeded')) {
                return;
            }

            if ($directive->status === 'pending') {
                $directive->fill([
                    'status' => $held['directive_outcome'],
                    'outcome' => ['result' => $held['directive_outcome']],
                    'completed_at' => $now,
                ])->save();
            }

            if ($directive->kind === 'respawn' && $held['directive_outcome'] === 'succeeded'
                && isset($held['launch_nonce'], $held['session_ref'])
                && hash_equals((string) ($directive->payload['launch_nonce'] ?? ''), $held['launch_nonce'])) {
                $accepted = WorkItem::query()->whereKey($locked->id)
                    ->where('fence', $locked->fence)
                    ->where('launch_nonce', $held['launch_nonce'])
                    ->whereNull('handed_off_session_ref')
                    ->update([
                        'handed_off_session_ref' => $held['session_ref'],
                        'handed_off_at' => $now,
                    ]);
                $current = $locked->refresh();
                $idempotent = $current->launch_nonce !== null
                    && hash_equals($current->launch_nonce, $held['launch_nonce'])
                    && $current->handed_off_session_ref !== null
                    && hash_equals($current->handed_off_session_ref, $held['session_ref']);
                if ($accepted === 1 || $idempotent) {
                    $this->seats->adoptVerifiedSession($current, $now);
                }
            }
        });
    }

    private function next(WorkItem $item, CarbonImmutable $now): ?WorkDirective
    {
        $exhausted = null;
        $directive = DB::transaction(function () use ($item, $now, &$exhausted): ?WorkDirective {
            $locked = WorkItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $reason = $locked->stalled_at !== null ? 'stall' : 'receipt';
            $anchor = $reason === 'stall' ? $locked->stalled_at : $locked->handed_off_at;
            if ($reason === 'receipt' && $locked->first_fetched_at !== null) {
                return null;
            }

            $history = $locked->directives()->where('fence', $locked->fence)->where('reason', $reason);
            $latest = (clone $history)
                ->reorder()->orderByDesc('sequence')->first();
            if ($anchor === null && $latest instanceof WorkDirective) {
                $anchor = $latest->completed_at ?? $latest->issued_at;
            }
            if ($anchor === null) {
                return null;
            }

            $episode = clone $history;
            if ($reason === 'stall') {
                $episode->where('issued_at', '>', $anchor);
            }

            $pending = (clone $episode)->where('status', 'pending')->first();
            if ($pending instanceof WorkDirective) {
                return $pending;
            }

            $completed = (clone $episode)->whereIn('status', ['succeeded', 'failed'])
                ->reorder()->orderByDesc('sequence')->get();
            $deadline = $this->deadline($locked);
            $lastCompleted = $completed->first()?->completed_at;
            $readyAt = $lastCompleted?->addSeconds($deadline) ?? $anchor->addSeconds($deadline);
            if ($readyAt->isAfter($now)) {
                return null;
            }

            $repair = $completed->count() + 1;
            if ($repair > count(self::REPAIRS)) {
                $exhausted = [
                    'task_id' => $locked->work_task_id,
                    'item_id' => $locked->id,
                    'fence' => $locked->fence,
                    'reason' => $reason,
                    'episode' => $reason === 'stall' ? $anchor->getTimestamp() : null,
                ];

                return null;
            }

            $sequence = ((int) (clone $history)->max('sequence')) + 1;
            $kind = self::REPAIRS[$repair - 1];
            $payload = [];
            if ($kind === 'respawn') {
                $nonce = (string) Str::uuid7();
                $locked->forceFill([
                    'launch_nonce' => $nonce,
                    'handed_off_at' => null,
                    'handed_off_session_ref' => null,
                    'first_fetched_at' => null,
                    'agent_model' => null,
                    'fetched_brief_revision_id' => null,
                    'fetched_page_version_id' => null,
                ])->save();
                $payload['launch_nonce'] = $nonce;
            }

            return $locked->directives()->create([
                'claimant_spoke_id' => $locked->claimant_spoke_id,
                'fence' => $locked->fence,
                'sequence' => $sequence,
                'kind' => $kind,
                'reason' => $reason,
                'payload' => $payload,
                'status' => 'pending',
                'issued_at' => $now,
            ]);
        });

        if (is_array($exhausted)) {
            $root = $this->root(WorkTask::query()->findOrFail($exhausted['task_id']));
            resolve(WorkOrchestrationService::class)->escalate(
                $root,
                'directive-exhausted:'.$exhausted['item_id'].':'.$exhausted['fence'].':'.$exhausted['reason']
                    .($exhausted['episode'] === null ? '' : ':'.$exhausted['episode']),
                null,
                $exhausted['reason'] === 'receipt' ? 'A worker did not fetch its brief after every launch repair.' : 'A running worker stayed stalled after every repair.',
                ['Inspect the worker output', 'Release and re-queue the item'],
                'Inspect the worker output',
            );
        }

        return $directive;
    }

    /** @return array<string, mixed> */
    private function representation(WorkDirective $directive): array
    {
        $result = [
            'id' => $directive->id,
            'item_id' => $directive->work_item_id,
            'fence' => $directive->fence,
            'sequence' => $directive->sequence,
            'kind' => $directive->kind,
            'reason' => $directive->reason,
            'payload' => (object) $directive->payload,
            'issued_at' => $directive->issued_at->toJSON(),
        ];
        if (in_array($directive->kind, ['resend_pointer', 'respawn'], true)) {
            $result['links'] = $this->links->forItem($directive->workItem);
        }

        return $result;
    }

    private function deadline(WorkItem $item): int
    {
        $key = $item->role === WorkItem::ROLE_ORCHESTRATOR
            ? 'capstan.work.orchestration.receipt_deadline_seconds'
            : 'capstan.work.receipt_deadline_seconds';

        return max(1, (int) config($key, $item->role === WorkItem::ROLE_ORCHESTRATOR ? 60 : 180));
    }

    private function root(WorkTask $task): WorkTask
    {
        while ($task->parent_task_id !== null) {
            $task = WorkTask::query()->findOrFail($task->parent_task_id);
        }

        return $task;
    }
}
