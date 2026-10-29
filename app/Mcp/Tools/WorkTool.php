<?php

namespace App\Mcp\Tools;

use App\Casts\WorkPullRequest;
use App\Enums\WorkItemState;
use App\Enums\WorkPriority;
use App\Http\ApiErrorException;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Models\Spoke;
use App\Models\Team;
use App\Work\GitHubRepository;
use App\Work\TeamWorkPolicy;
use App\Work\WorkPriorityService;
use App\Work\WorkReassignmentService;
use App\Work\WorkRepresentation;
use App\Work\WorkToolService;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class WorkTool extends CapstanTool
{
    public function schema(JsonSchema $schema): array
    {
        $properties = ['team_id' => $schema->integer()->min(1)->required()];
        if ($this->isWrite()) {
            $properties['idempotency_key'] = $schema->string()->min(1)->max(255)->required();
        }

        return [...$properties, ...match ($this->name()) {
            'create_task' => [
                'title' => $schema->string()->min(1)->max(255)->required(),
                'brief' => $schema->string()->min(1)->max(32000),
                'page_id' => $schema->string(),
                'parent_task_id' => $schema->string(),
                'user_actor_address' => $schema->string()->min(1)->max(19),
                'device_spoke_id' => $schema->integer()->min(1),
                'failover' => $schema->boolean(),
                'required_capabilities' => $schema->array()->items($schema->string()->min(1)->max(64))->max(25)->unique(),
                'timebox_minutes' => $schema->integer()->min(1)->max(1440),
                'owner_actor' => $schema->string()->min(1)->max(19),
                'priority' => $schema->string()->enum(WorkPriority::class),
                'depends_on' => $schema->array()->items($schema->string())->max(25)->unique(),
                'expires_at' => $schema->string(),
                'repository' => $schema->string()->min(1)->max(GitHubRepository::MAX_LENGTH),
                'workflow_ref' => $schema->string()->min(1)->max(2048),
                'workflow_sha' => $schema->string()->min(40)->max(40),
                'pr' => $schema->object([
                    'repo' => $schema->string()->min(1)->max(255)->required(),
                    'number' => $schema->integer()->min(1)->required(),
                    'url' => $schema->string()->min(1)->max(2048)->required(),
                ])->withoutAdditionalProperties(),
            ],
            'kickoff' => [
                'title' => $schema->string()->min(1)->max(255)->required(),
                'page_id' => $schema->string()->required(),
                'priority' => $schema->string()->enum(WorkPriority::class),
                'repository' => $schema->string()->min(1)->max(GitHubRepository::MAX_LENGTH),
                'workflow_ref' => $schema->string()->min(1)->max(2048),
                'workflow_sha' => $schema->string()->min(40)->max(40),
            ],
            'revise_brief' => ['task_id' => $schema->string()->required(), 'brief' => $schema->string()->min(1)->max(32000)->required()],
            'queue_item' => ['task_id' => $schema->string()->required(), 'role' => $schema->string()->min(1)->max(64)->required()],
            'cancel_item' => ['item_id' => $schema->string()->required()],
            'steer' => ['task_id' => $schema->string()->required(), 'item_id' => $schema->string(), 'content' => $schema->string()->min(1)->max(8000)->required()],
            'list_work' => ['task_id' => $schema->string(), 'state' => $schema->string()->enum(WorkItemState::class), 'limit' => $schema->integer()->min(1)->max(25)->default(25)],
            'get_team_work_policy' => [],
            'set_team_work_policy' => ['pool_work' => $schema->boolean()->required()],
            'reassign_task' => [
                'task_id' => $schema->string()->required(),
                'to_actor' => $schema->string()->min(1)->max(19)->required(),
                'mode' => $schema->string()->enum([WorkReassignmentService::MODE_MOVE, WorkReassignmentService::MODE_ESCALATIONS])->required(),
                'note' => $schema->string()->min(1)->max(2000),
            ],
            'set_task_priority' => [
                'task_id' => $schema->string()->required(),
                'priority' => $schema->string()->enum(WorkPriority::class)->required(),
                'confirmation_token' => $schema->string()->min(64)->max(64),
                'reason' => $schema->string()->min(1)->max(2000),
            ],
            default => ['task_id' => $schema->string()->required()],
        }];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        $representation = new WorkRepresentation;
        $policy = $schema->object([
            'team_id' => $schema->integer()->required(),
            'team_name' => $schema->string()->required(),
            'pool_work' => $schema->boolean()->required(),
        ])->withoutAdditionalProperties();
        $properties = match ($this->name()) {
            'get_team_work_policy' => ['policy' => $policy->required()],
            'set_team_work_policy' => ['policy' => $policy, 'changed' => $schema->boolean()],
            'create_task' => ['task' => $representation->schema($schema, 'task'), 'revision' => $representation->schema($schema, 'revision')],
            'kickoff' => ['task' => $representation->schema($schema, 'task'), 'item' => $representation->schema($schema, 'item')],
            'revise_brief' => ['revision' => $representation->schema($schema, 'revision')],
            'queue_item', 'cancel_item' => ['item' => $representation->schema($schema, 'item')],
            'steer' => ['steer' => $representation->schema($schema, 'steer')],
            'cancel_task' => ['task' => $representation->schema($schema, 'task')],
            'set_task_priority' => [
                'task_id' => $schema->string(), 'priority_changed' => $schema->boolean(),
                'priority' => $schema->string()->enum(WorkPriority::class),
                'confirmation_status' => $schema->string()->enum(['required', 'refused']),
                'confirmation_token' => $schema->string(),
                'reason' => $schema->string()->enum(['preemption_plan_changed']),
                'preemptions' => $schema->array()->items($schema->object([
                    'item_id' => $schema->string()->required(), 'role' => $schema->string()->required(),
                    'machine_id' => $schema->integer()->required(), 'machine_name' => $schema->string()->required(),
                    'owner_actor' => $schema->string()->required(),
                    'milestone_kind' => $schema->string(), 'milestone_at' => $schema->string(),
                ])->withoutAdditionalProperties()),
            ],
            'reassign_task' => [
                'task_id' => $schema->string(),
                'old_owner' => $schema->string(),
                'new_owner' => $schema->string(),
                'old_escalation_actor' => $schema->string(),
                'new_escalation_actor' => $schema->string(),
                'mode' => $schema->string()->enum([WorkReassignmentService::MODE_MOVE, WorkReassignmentService::MODE_ESCALATIONS]),
            ],
            'get_task' => [
                'task' => $representation->schema($schema, 'task')->required(),
                'revisions' => $schema->array()->items($representation->schema($schema, 'revision'))->required(),
                'items' => $schema->array()->items($representation->schema($schema, 'item'))->required(),
                'milestones' => $schema->array()->items($representation->schema($schema, 'milestone'))->required(),
                'steers' => $schema->array()->items($representation->schema($schema, 'steer'))->required(),
            ],
            default => [
                'groups' => $schema->object(array_combine(
                    array_column(WorkItemState::cases(), 'value'),
                    array_map(fn () => $schema->array()->items($representation->schema($schema, 'item'))->required(), WorkItemState::cases()),
                ))->withoutAdditionalProperties()->required(),
                'limit' => $schema->integer()->min(1)->max(25)->required(),
                'has_more' => $schema->boolean()->required(),
            ],
        };
        if ($this->isWrite()) {
            $properties['outcome'] = $schema->string()->enum(['accepted', 'already_accepted', 'refused', 'in_progress', 'outcome_unknown'])->required();
            $properties['reason'] = $schema->string();
        }

        return $properties;
    }

    public function handle(Request $request, OwnerSubjectResolver $owners, DurableWriteCoordinator $writes, WorkToolService $work, TeamWorkPolicy $policy, WorkReassignmentService $reassignments, WorkPriorityService $priorities): Response|ResponseFactory
    {
        $schema = $this->schema(new JsonSchemaTypeFactory);
        $this->requireExactArguments($request, array_keys($schema));
        $rules = [];
        foreach ($schema as $field => $property) {
            $definition = $property->toArray();
            $raw = $this->rawArgument($field);
            if (array_key_exists($field, $request->all())) {
                $valid = match ($definition['type']) {
                    'integer' => is_int($raw), 'boolean' => is_bool($raw),
                    'array' => is_array($raw), 'object' => $raw instanceof \stdClass, default => is_string($raw),
                };
                if (! $valid) {
                    throw ValidationException::withMessages([$field => ['Invalid argument type.']]);
                }
            }
            $rules[$field] = match ($field) {
                'team_id', 'device_spoke_id' => [$field === 'team_id' ? 'required' : 'sometimes', 'required', 'integer', 'min:1'],
                'idempotency_key', 'title' => ['required', 'string', 'min:1', 'max:255'],
                'brief' => [$this->name() === 'create_task' ? 'sometimes' : 'required', 'required', 'string', 'min:1', 'max:32000'],
                'content' => ['required', 'string', 'min:1', 'max:8000'],
                'role' => ['required', 'string', 'min:1', 'max:64'],
                'task_id', 'item_id' => [($field === 'item_id' && $this->name() === 'steer') || $this->name() === 'list_work' ? 'sometimes' : 'required', 'required', 'string', 'uuid'],
                'page_id' => [$this->name() === 'kickoff' ? 'required' : 'sometimes', 'required', 'string', 'uuid'],
                'parent_task_id' => ['sometimes', 'required', 'string', 'uuid'],
                'failover' => ['sometimes', 'required', 'boolean'],
                'pool_work' => ['required', 'boolean'],
                'user_actor_address', 'owner_actor' => ['sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
                'timebox_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
                'required_capabilities' => ['sometimes', 'array', 'list', 'max:25'],
                'depends_on' => ['sometimes', 'array', 'list', 'max:25'],
                'priority' => [$this->name() === 'set_task_priority' ? 'required' : 'sometimes', 'required', Rule::enum(WorkPriority::class)],
                'expires_at' => ['sometimes', 'required', 'date'],
                'repository' => ['sometimes', 'required', 'string', 'max:'.GitHubRepository::MAX_LENGTH],
                'workflow_ref' => ['sometimes', 'required', 'string', 'min:1', 'max:2048'],
                'workflow_sha' => ['sometimes', 'required', 'string', 'regex:/\A[a-f0-9]{40}\z/D'],
                'pr' => ['sometimes', 'required', 'array'],
                'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:25'],
                'state' => ['sometimes', 'required', Rule::enum(WorkItemState::class)],
                'to_actor' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
                'mode' => ['required', 'string', Rule::in([WorkReassignmentService::MODE_MOVE, WorkReassignmentService::MODE_ESCALATIONS])],
                'note' => ['sometimes', 'required', 'string', 'min:1', 'max:2000'],
                'confirmation_token' => ['sometimes', 'required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
                'reason' => ['sometimes', 'required', 'string', 'min:1', 'max:2000'],
                default => throw new \LogicException('Unknown Work input.'),
            };
        }
        $rules['required_capabilities.*'] = ['required', 'string', 'min:1', 'max:64', 'distinct:strict'];
        $rules['depends_on.*'] = ['required', 'string', 'uuid', 'distinct:strict'];
        $rules['pr.repo'] = ['required_with:pr', 'string', 'min:1', 'max:255'];
        $rules['pr.number'] = ['required_with:pr', 'integer', 'min:1'];
        $rules['pr.url'] = ['required_with:pr', 'string', 'max:2048', 'url:https'];
        $rawPr = $this->rawArgument('pr');
        if ($rawPr instanceof \stdClass) {
            $prKeys = array_keys(get_object_vars($rawPr));
            sort($prKeys);
            if ($prKeys !== ['number', 'repo', 'url']) {
                throw ValidationException::withMessages(['pr' => ['PR metadata has invalid fields.']]);
            }
        }
        $input = $request->validate($rules);
        if (isset($input['repository'])) {
            try {
                $input['repository'] = GitHubRepository::normalize($input['repository']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['repository' => ['Use a GitHub owner/repository slug.']]);
            }
        }
        if ($this->name() === 'create_task' && (isset($input['brief']) === isset($input['page_id']))) {
            throw ValidationException::withMessages(['brief' => ['Provide exactly one of brief or page_id.']]);
        }
        if ($this->name() === 'kickoff' && ! isset($input['page_id'])) {
            throw ValidationException::withMessages(['page_id' => ['A kickoff requires a Page.']]);
        }
        if (isset($input['workflow_ref']) !== isset($input['workflow_sha'])) {
            throw ValidationException::withMessages(['workflow_sha' => ['workflow_ref and workflow_sha must be provided together.']]);
        }
        if (isset($input['pr'])) {
            $input['pr'] = WorkPullRequest::normalize($input['pr']);
        }
        if ($this->name() === 'set_task_priority') {
            if (isset($input['confirmation_token']) && (($input['priority'] ?? null) !== 'P0' || ! isset($input['reason']))) {
                throw ValidationException::withMessages(['reason' => ['P0 confirmation requires a reason.']]);
            }
            if (isset($input['reason']) && (! isset($input['confirmation_token']) || preg_match('~https?://|signature=|bearer\s|(?:token|password|secret|api[_-]?key)\s*[:=]|\b(?:sk-|ghp_|github_pat_)~i', $input['reason']) === 1)) {
                throw ValidationException::withMessages(['reason' => ['Use a short reason without URLs or credentials, only when confirming P0.']]);
            }
        }
        $owner = $owners->resolve();
        $user = User::query()->find($owner->actorId);
        $admitted = $user instanceof User && $user->status === 'active'
            && RolePolicy::canUseProduct($user->role)
            && ! OffboardedSubject::userIsOffboarded($owner->actorId);
        if (! $admitted || (! in_array($this->name(), ['reassign_task', 'set_task_priority'], true) && ! RolePolicy::canManageMembers($user->role))) {
            throw new AuthorizationException($this->name() === 'reassign_task'
                ? 'Only an admitted user may reassign Work.'
                : 'Only an admitted Owner or Admin may manage Work.');
        }
        $team = Team::query()->find($input['team_id']);
        if (! $team instanceof Team) {
            return Response::error('Work team is unavailable.');
        }
        if ($this->name() === 'set_task_priority') {
            try {
                $priorities->authorize($team->id, $input['task_id'], $owner->actorId);
            } catch (ApiErrorException) {
                return Response::error('Work task is unavailable.');
            }
        }
        if (in_array($this->name(), ['create_task', 'kickoff'], true)) {
            if (isset($input['device_spoke_id']) && ! array_key_exists('failover', $input)) {
                throw ValidationException::withMessages(['failover' => ['A device pin requires explicit failover.']]);
            }
            if (isset($input['device_spoke_id'])) {
                $spoke = Spoke::query()->workEligible()->whereKey($input['device_spoke_id'])->first();
                if ($spoke === null || ! User::query()->whereKey($spoke->actor_id)->exists()) {
                    throw ValidationException::withMessages(['device_spoke_id' => ['Work host is unavailable.']]);
                }
            }
            if (isset($input['user_actor_address']) && ! User::query()->whereKey($input['user_actor_address'])->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['user_actor_address' => ['Work actor is unavailable.']]);
            }
            if (isset($input['owner_actor']) && ! User::query()->whereKey($input['owner_actor'])->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['owner_actor' => ['Work owner is unavailable.']]);
            }
            // Freeze only caller-specified intent in the durable claim; defaults may change on replay.
        }
        if ($this->isWrite()) {
            try {
                $result = $writes->run(
                    owner: $owner, tool: $this->name(), idempotencyKey: $input['idempotency_key'],
                    intent: $input, targetType: 'work',
                    operation: function (string $targetId, string $claimId) use ($work, $policy, $reassignments, $priorities, $input, $team, $owner, $user): array {
                        if ($this->name() === 'set_task_priority') {
                            return $priorities->change($input, $owner->actorId, $claimId);
                        }
                        if ($this->name() === 'set_team_work_policy') {
                            $changed = $policy->setPoolWork($team, $user, $input['pool_work'], TeamWorkPolicy::SURFACE_MCP);

                            return ['policy' => $policy->present($team), 'changed' => $changed];
                        }
                        if (in_array($this->name(), ['create_task', 'kickoff'], true)) {
                            $input += [
                                'failover' => (bool) $team->default_failover,
                                'required_capabilities' => [],
                                'timebox_minutes' => 30,
                                'owner_actor' => $owner->actorId,
                                'priority' => WorkPriority::P2->value,
                                'depends_on' => [],
                            ];
                        }
                        if ($this->name() === 'reassign_task') {
                            return $reassignments->reassign(
                                $team->id,
                                $input['task_id'],
                                $owner->actorId,
                                $input['to_actor'],
                                $input['mode'],
                                $input['note'] ?? null,
                                $claimId,
                            );
                        }

                        return $work->write($this->name(), $input, $owner->actorId);
                    },
                );
            } catch (JsonRpcException $exception) {
                if ($exception->getCode() !== -32003 || ! in_array($exception->getMessage(), [
                    'The write service is unavailable.',
                    'The write service is temporarily unavailable.',
                ], true)) {
                    throw $exception;
                }

                return Response::error($exception->getMessage());
            }
        } elseif ($this->name() === 'get_team_work_policy') {
            $result = ['policy' => $policy->present($team)];
        } else {
            try {
                $result = $work->read($this->name(), $input);
            } catch (ApiErrorException) {
                return Response::error('Work task is unavailable.');
            }
        }
        // Durable replay decodes JSON objects as arrays. Preserve the published empty-object payload shape.
        if (($result['confirmation_status'] ?? null) === 'refused') {
            $result['outcome'] = 'refused';
        }
        if (isset($result['task']['pr']) && is_array($result['task']['pr'])) {
            $result['task']['pr'] = WorkPullRequest::normalize($result['task']['pr']);
        }
        if (isset($result['item']['latest_milestone']['payload'])) {
            $payload = (array) $result['item']['latest_milestone']['payload'];
            if (isset($payload['pr']) && (is_array($payload['pr']) || is_object($payload['pr']))) {
                $payload['pr'] = WorkPullRequest::normalize((array) $payload['pr']);
            }
            $result['item']['latest_milestone']['payload'] = (object) $payload;
        }
        if (! ToolResponse::fits($result)) {
            return Response::error('Work result exceeds the response budget.');
        }

        return ToolResponse::structured($result);
    }

    private function isWrite(): bool
    {
        return ! in_array($this->name(), ['list_work', 'get_task', 'get_team_work_policy'], true);
    }
}
