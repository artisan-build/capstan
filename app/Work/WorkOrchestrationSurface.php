<?php

namespace App\Work;

use App\Enums\WorkItemState;
use App\Enums\WorkPriority;
use App\Http\ApiErrorException;
use App\Models\Spoke;
use App\Models\Team;
use App\Models\WorkItem;
use App\Models\WorkTask;
use App\Support\JsonCanonicalizer;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Resolves production authority and exposes one bounded action envelope. */
final readonly class WorkOrchestrationSurface
{
    public function __construct(private WorkOrchestrationCapability $capability) {}

    public function claimed(string $itemId, int $itemFence): WorkOrchestrationContext
    {
        $item = WorkItem::query()->whereKey($itemId)->where('role', WorkItem::ROLE_ORCHESTRATOR)->first();
        if (! $item instanceof WorkItem || $item->claimant_actor === null || $item->claimant_spoke_id === null
            || $item->seat_fence === null) {
            throw $this->forbidden();
        }

        return new WorkOrchestrationContext(
            $item->work_task_id,
            $item->claimant_actor,
            $item->claimant_spoke_id,
            $item->seat_fence,
            null,
            $item->id,
            $itemFence,
        );
    }

    public function interactive(string $parentTaskId, string $actor, int $spokeId, int $seatFence, string $sessionRef): WorkOrchestrationContext
    {
        return new WorkOrchestrationContext($parentTaskId, $actor, $spokeId, $seatFence, $sessionRef);
    }

    /** @return array<string, mixed> */
    public function read(WorkOrchestrationContext $context, ?int $after, CarbonImmutable $now, ?string $decisionsAfter = null): array
    {
        return $this->capability->context($context, $after, $now, $decisionsAfter);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function act(WorkOrchestrationContext $context, string $action, array $input, CarbonImmutable $now): array
    {
        $input = $this->validate($action, $input);

        return match ($action) {
            'create_child' => $this->created($action, $this->capability->createChild(
                $context,
                $input['under_task_id'],
                $input,
                $input['brief'],
                $now,
            )),
            'set_priority' => $this->created($action, $this->capability->setPriority($context, $input['priority'], $now)),
            'revise_brief' => $this->created($action, $this->capability->reviseBrief($context, $input['task_id'], $input['brief'], $now)),
            'queue_item' => $this->queued($action, $this->capability->queue($context, $input['task_id'], $input['role'], $now)),
            'cancel_item' => $this->created($action, $this->capability->cancelItem($context, $input['item_id'], $now)),
            'steer' => $this->created($action, $this->capability->steer($context, $input['task_id'], $input['item_id'] ?? null, $input['content'], $now)),
            'decide' => $this->created($action, $this->capability->decide($context, $input['event_id'], $input['decision'], $input['rationale'], $input['evidence'], $now)),
            'request_wake' => $this->created($action, $this->capability->requestWake($context, $input['key'], CarbonImmutable::parse($input['due_at']), $input['reason'], $now)),
            'escalate' => $this->created($action, $this->capability->escalate($context, $input['key'], $input['addressed_actor'], $input['question'], $input['options'], $input['recommendation'], $now)),
            'finish' => $this->created($action, $this->capability->finish($context, WorkItemState::from($input['state']), $input['content'] ?? null, $now)),
            default => throw $this->invalid(),
        };
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(string $action, array $input): array
    {
        $shape = match ($action) {
            'create_child' => ['under_task_id', 'title', 'brief', 'user_actor_address', 'device_spoke_id', 'failover', 'required_capabilities', 'timebox_minutes', 'workflow_ref', 'owner_actor', 'priority', 'depends_on', 'expires_at', 'pr'],
            'set_priority' => ['priority'],
            'revise_brief' => ['task_id', 'brief'],
            'queue_item' => ['task_id', 'role'],
            'cancel_item' => ['item_id'],
            'steer' => ['task_id', 'item_id', 'content'],
            'decide' => ['event_id', 'decision', 'rationale', 'evidence'],
            'request_wake' => ['key', 'due_at', 'reason'],
            'escalate' => ['key', 'addressed_actor', 'question', 'options', 'recommendation'],
            'finish' => ['state', 'content'],
            default => throw $this->invalid(),
        };
        if (array_diff(array_keys($input), $shape) !== []) {
            throw $this->invalid();
        }
        $rules = match ($action) {
            'create_child' => [
                'under_task_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'min:1', 'max:255'],
                'brief' => ['required', 'string', 'min:1', 'max:32000'], 'user_actor_address' => ['sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
                'device_spoke_id' => ['sometimes', 'integer', 'min:1'], 'failover' => ['sometimes', 'boolean'],
                'required_capabilities' => ['sometimes', 'array', 'list', 'max:25'], 'required_capabilities.*' => ['string', 'min:1', 'max:64', 'distinct'],
                'timebox_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'], 'workflow_ref' => ['sometimes', 'string', 'max:2048'],
                'owner_actor' => ['sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
                'priority' => ['sometimes', 'required', Rule::enum(WorkPriority::class)],
                'depends_on' => ['sometimes', 'array', 'list', 'max:25'], 'depends_on.*' => ['required', 'uuid', 'distinct:strict'],
                'expires_at' => ['sometimes', 'required', 'date'], 'pr' => ['sometimes', 'required', 'array'],
                'pr.repo' => ['required_with:pr', 'string', 'min:1', 'max:255'],
                'pr.number' => ['required_with:pr', 'integer', 'min:1'],
                'pr.url' => ['required_with:pr', 'string', 'max:2048', 'url:https'],
            ],
            'set_priority' => ['priority' => ['required', Rule::enum(WorkPriority::class)]],
            'revise_brief' => ['task_id' => ['required', 'uuid'], 'brief' => ['required', 'string', 'min:1', 'max:32000']],
            'queue_item' => ['task_id' => ['required', 'uuid'], 'role' => ['required', 'string', 'min:1', 'max:64', Rule::notIn([WorkItem::ROLE_HUMAN, WorkItem::ROLE_ORCHESTRATOR])]],
            'cancel_item' => ['item_id' => ['required', 'uuid']],
            'steer' => ['task_id' => ['required', 'uuid'], 'item_id' => ['sometimes', 'uuid'], 'content' => ['required', 'string', 'min:1', 'max:8000']],
            'decide' => ['event_id' => ['required', 'uuid'], 'decision' => ['required', 'string', 'min:1', 'max:10000'], 'rationale' => ['required', 'string', 'min:1', 'max:10000'], 'evidence' => ['required', 'array', 'max:25']],
            'request_wake' => ['key' => ['required', 'string', 'min:1', 'max:255'], 'due_at' => ['required', 'date'], 'reason' => ['required', 'string', 'min:1', 'max:2000']],
            'escalate' => ['key' => ['required', 'string', 'min:1', 'max:255'], 'addressed_actor' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'question' => ['required', 'string', 'min:1', 'max:10000'], 'options' => ['required', 'array', 'list', 'min:1', 'max:10'], 'options.*' => ['required', 'string', 'min:1', 'max:2000'], 'recommendation' => ['required', 'string', 'min:1', 'max:2000']],
            'finish' => ['state' => ['required', Rule::in([WorkItemState::Acted->value, WorkItemState::Failed->value])], 'content' => ['required_if:state,acted', 'string', 'min:1', 'max:100000']],
        };
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw $this->invalid();
        }
        if (isset($input['pr']) && is_array($input['pr'])) {
            $prKeys = array_keys($input['pr']);
            sort($prKeys);
            if ($prKeys !== ['number', 'repo', 'url']) {
                throw $this->invalid();
            }
        }
        $validated = $validator->validated();
        if ($action === 'create_child') {
            if (isset($validated['device_spoke_id']) && ! array_key_exists('failover', $input)) {
                throw $this->invalid();
            }
            if (isset($validated['device_spoke_id'])) {
                $spoke = Spoke::query()->workEligible()->whereKey($validated['device_spoke_id'])->first();
                if (! $spoke instanceof Spoke || ! User::query()->whereKey($spoke->actor_id)->exists()) {
                    throw $this->invalid();
                }
            }
            if (isset($validated['user_actor_address']) && ! User::query()->whereKey($validated['user_actor_address'])->where('status', 'active')->exists()) {
                throw $this->invalid();
            }
            if (isset($validated['owner_actor']) && ! User::query()->whereKey($validated['owner_actor'])->where('status', 'active')->exists()) {
                throw $this->invalid();
            }
            $under = WorkTask::query()->whereKey($validated['under_task_id'])->first();
            $team = $under instanceof WorkTask ? Team::query()->find($under->team_id) : null;
            if (! $team instanceof Team) {
                throw $this->invalid();
            }
            $validated += ['required_capabilities' => [], 'timebox_minutes' => 30, 'failover' => (bool) $team->default_failover, 'depends_on' => []];
        }
        if ($action === 'decide' && (! $this->safeEvidence($validated['evidence']) || strlen(JsonCanonicalizer::encode($validated['evidence'])) > 8000)) {
            throw $this->invalid();
        }
        if ($action === 'request_wake') {
            $due = CarbonImmutable::parse($validated['due_at']);
            $now = CarbonImmutable::now();
            if ($due->lt($now) || $due->gt($now->addDays(max(1, (int) config('capstan.work.orchestration.wake_horizon_days', 30))))) {
                throw new ApiErrorException(422, 'wake_outside_horizon', 'The wake must be due between now and the wake horizon.');
            }
        }
        if ($action === 'escalate' && ! User::query()->whereKey($validated['addressed_actor'])->where('status', 'active')->exists()) {
            throw $this->invalid();
        }

        return $validated;
    }

    /** @param array<array-key, mixed> $value */
    private function safeEvidence(array $value, int $depth = 0): bool
    {
        if ($depth > 6 || ($depth === 0 && $value !== [] && array_is_list($value))) {
            return false;
        }
        foreach ($value as $entry) {
            if (is_array($entry) && ! $this->safeEvidence($entry, $depth + 1)) {
                return false;
            }
            if (is_string($entry) && strlen($entry) > 8000) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function created(string $action, Model $model): array
    {
        return array_filter([
            'action' => $action, 'status' => 'completed', 'id' => $model->getKey(),
            'task_id' => $model->work_task_id ?? ($model instanceof WorkTask ? $model->id : null),
            'item_id' => $model instanceof WorkItem ? $model->id : ($model->work_item_id ?? null),
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function queued(string $action, ?WorkItem $item): array
    {
        return $item === null
            ? ['action' => $action, 'status' => 'budget_exhausted']
            : $this->created($action, $item);
    }

    private function invalid(): ApiErrorException
    {
        return new ApiErrorException(422, 'invalid_orchestration_action', 'The orchestration action payload is invalid.');
    }

    private function forbidden(): ApiErrorException
    {
        return new ApiErrorException(403, 'orchestration_forbidden', 'Orchestrator turn authority was lost.');
    }
}
