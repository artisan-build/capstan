<?php

namespace App\Work;

use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\WorkEscalation;
use App\Models\WorkItem;
use App\Models\WorkOrchestrationEvent;
use App\Models\WorkOrchestratorSeat;
use App\Models\WorkTask;
use App\Support\BoundCredentialSpokeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class WorkOrchestrationMcpService
{
    public function __construct(
        private WorkOrchestrationLock $locks,
        private WorkSeatService $seats,
        private WorkOrchestrationService $orchestration,
        private BoundCredentialSpokeResolver $spokes,
    ) {}

    /** @return array<string, mixed> */
    public function take(int $teamId, string $taskId, string $actor, string $credentialId, ?int $spokeId, string $sessionRef, int $after, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($teamId, $taskId, $actor, $credentialId, $spokeId, $sessionRef, $after, $now): array {
            $parent = $this->task($teamId, $taskId);
            $spoke = $this->spoke($actor, $credentialId, $spokeId, $now);
            $this->locks->tasks($parent->id);
            $seat = $this->locks->seat($parent);
            $same = $seat->holder_actor === $actor && $seat->holder_spoke_id === $spoke->id;

            if ($same) {
                if (! $seat->interactive || $seat->lease_until === null || $seat->lease_until->lte($now)) {
                    throw $this->refused('seat_authority_lost');
                }
                $seat = $this->seats->renew($parent, $actor, $spoke->id, $seat->fence, $sessionRef, $now);
            } else {
                $active = $seat->active_item_id === null ? null : WorkItem::query()->whereKey($seat->active_item_id)->first();
                if (! $this->seats->eligible($seat, $actor, $spoke->id, $now, $parent->device_spoke_id, $active?->id, $active?->updated_at)) {
                    throw $this->refused('seat_unavailable');
                }
                $seat = $this->seats->take($parent, $actor, $spoke->id, $seat->fence, $sessionRef, true, $now);
            }
            if (! $seat instanceof WorkOrchestratorSeat) {
                throw $this->refused('seat_unavailable');
            }

            return $this->context($parent, $seat, $spoke, $after);
        }, 5);
    }

    /** @return array{released: true, task_id: string, seat_fence: int} */
    public function release(int $teamId, string $taskId, string $actor, string $credentialId, ?int $spokeId, int $seatFence, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($teamId, $taskId, $actor, $credentialId, $spokeId, $seatFence, $now): array {
            $parent = $this->task($teamId, $taskId);
            $spoke = $this->spoke($actor, $credentialId, $spokeId, $now);
            $this->locks->tasks($parent->id);
            $seat = $this->locks->seat($parent);
            if (! $seat->interactive || $seat->fence !== $seatFence || $seat->holder_actor !== $actor
                || $seat->holder_spoke_id !== $spoke->id || $seat->lease_until === null || $seat->lease_until->lte($now)) {
                throw $this->refused('seat_authority_lost');
            }
            $released = $this->seats->release($parent, $actor, $spoke->id, $seatFence, $now);
            if (! $released instanceof WorkOrchestratorSeat) {
                throw $this->refused('seat_authority_lost');
            }

            return ['released' => true, 'task_id' => $parent->id, 'seat_fence' => $released->fence];
        }, 5);
    }

    /** @return array{escalations: list<array<string, mixed>>, limit: int, has_more: bool} */
    public function escalations(int $teamId, string $actor, int $limit): array
    {
        $rows = WorkEscalation::query()
            ->where('addressed_actor', $actor)
            ->whereNull('answered_at')
            ->whereHas('workTask', fn ($query) => $query->where('team_id', $teamId)->whereNull('cancelled_at'))
            ->whereHas('workItem', fn ($query) => $query->where('role', WorkItem::ROLE_HUMAN)
                ->where('addressed_actor', $actor)->where('state', 'queued'))
            ->orderBy('id')->limit($limit + 1)->get();

        return [
            'escalations' => array_values($rows->take($limit)->map($this->escalation(...))->all()),
            'limit' => $limit,
            'has_more' => $rows->count() > $limit,
        ];
    }

    /** @return array<string, mixed> */
    public function answer(int $teamId, string $escalationId, string $actor, string $answer, CarbonImmutable $now): array
    {
        $escalation = WorkEscalation::query()->whereKey($escalationId)
            ->where('addressed_actor', $actor)
            ->whereHas('workTask', fn ($query) => $query->where('team_id', $teamId))
            ->first();
        if (! $escalation instanceof WorkEscalation) {
            throw $this->refused('escalation_unavailable');
        }

        try {
            $answered = $this->orchestration->answer($escalation, $actor, $answer, $now);
        } catch (\DomainException) {
            throw $this->refused('escalation_unavailable');
        }
        if (! $answered instanceof WorkEscalation) {
            throw $this->refused('escalation_unavailable');
        }

        return ['escalation' => $this->escalation($answered)];
    }

    private function task(int $teamId, string $taskId): WorkTask
    {
        $task = WorkTask::query()->whereKey($taskId)->where('team_id', $teamId)->first();
        if (! $task instanceof WorkTask || $task->cancelled_at !== null) {
            throw $this->refused('task_unavailable');
        }

        return $task;
    }

    private function spoke(string $actor, string $credentialId, ?int $spokeId, CarbonImmutable $now): Spoke
    {
        if ($spokeId === null) {
            return $this->spokes->resolve($actor, $credentialId, $now);
        }
        $spoke = Spoke::query()->whereKey($spokeId)->where('actor_id', $actor)->lockForUpdate()->first();
        if (! $spoke instanceof Spoke) {
            throw $this->refused('spoke_unavailable');
        }

        return $spoke;
    }

    /** @return array<string, mixed> */
    private function context(WorkTask $parent, WorkOrchestratorSeat $seat, Spoke $spoke, int $after): array
    {
        $lower = max($seat->completed_cursor, $after);
        $events = WorkOrchestrationEvent::query()->where('work_task_id', $parent->id)
            ->where('sequence', '>', $lower)->orderBy('sequence')->limit(26)->get();

        return [
            'seat' => [
                'task_id' => $parent->id,
                'seat_fence' => $seat->fence,
                'lease_until' => $seat->lease_until?->toJSON(),
                'session_ref' => $seat->session_ref,
                'cursor' => $seat->completed_cursor,
                'through' => $seat->event_sequence,
                'spoke_id' => $spoke->id,
            ],
            'events' => $events->take(25)->map(fn (WorkOrchestrationEvent $event): array => [
                'id' => $event->id,
                'sequence' => $event->sequence,
                'kind' => $event->kind,
                'evidence' => $this->boundedJson($event->evidence),
            ])->values()->all(),
            'has_more' => $events->count() > 25,
        ];
    }

    /** @return array<string, mixed> */
    private function escalation(WorkEscalation $escalation): array
    {
        $options = $escalation->metadata['options'] ?? [];
        $recommendation = $escalation->metadata['recommendation'] ?? '';

        return array_filter([
            'id' => $escalation->id,
            'task_id' => $escalation->work_task_id,
            'item_id' => $escalation->work_item_id,
            'question' => $escalation->question,
            'options' => is_array($options) ? array_values(array_filter($options, 'is_string')) : [],
            'recommendation' => is_string($recommendation) ? $recommendation : '',
            'answer' => $escalation->answer,
            'answered_at' => $escalation->answered_at?->toJSON(),
            'created_at' => $escalation->created_at?->toJSON(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $value */
    private function boundedJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) && strlen($json) <= 8000 ? $json : '{"truncated":true}';
    }

    private function refused(string $code): ApiErrorException
    {
        return new ApiErrorException(403, $code, 'The requested Work orchestration operation is unavailable.');
    }
}
