<?php

namespace App\Mcp\Tools;

use App\Enums\WorkItemState;
use App\Enums\WorkPriority;
use App\Http\ApiErrorException;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Models\Spoke;
use App\Models\Team;
use App\Work\TeamWorkPolicy;
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
                'brief' => $schema->string()->min(1)->max(32000)->required(),
                'user_actor_address' => $schema->string()->min(1)->max(19),
                'device_spoke_id' => $schema->integer()->min(1),
                'failover' => $schema->boolean(),
                'required_capabilities' => $schema->array()->items($schema->string()->min(1)->max(64))->max(25)->unique(),
                'timebox_minutes' => $schema->integer()->min(1)->max(1440),
                'owner_actor' => $schema->string()->min(1)->max(19),
                'priority' => $schema->string()->enum(WorkPriority::class),
                'depends_on' => $schema->array()->items($schema->string())->max(25)->unique(),
                'expires_at' => $schema->string(),
                'pr' => $schema->object([
                    'repo' => $schema->string()->min(1)->max(255)->required(),
                    'number' => $schema->integer()->min(1)->required(),
                    'url' => $schema->string()->min(1)->max(2048)->required(),
                ])->withoutAdditionalProperties(),
            ],
            'revise_brief' => ['task_id' => $schema->string()->required(), 'brief' => $schema->string()->min(1)->max(32000)->required()],
            'queue_item' => ['task_id' => $schema->string()->required(), 'role' => $schema->string()->min(1)->max(64)->required()],
            'cancel_item' => ['item_id' => $schema->string()->required()],
            'steer' => ['task_id' => $schema->string()->required(), 'item_id' => $schema->string(), 'content' => $schema->string()->min(1)->max(8000)->required()],
            'list_work' => ['task_id' => $schema->string(), 'state' => $schema->string()->enum(WorkItemState::class), 'limit' => $schema->integer()->min(1)->max(25)->default(25)],
            'get_team_work_policy' => [],
            'set_team_work_policy' => ['pool_work' => $schema->boolean()->required()],
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
            'revise_brief' => ['revision' => $representation->schema($schema, 'revision')],
            'queue_item', 'cancel_item' => ['item' => $representation->schema($schema, 'item')],
            'steer' => ['steer' => $representation->schema($schema, 'steer')],
            'cancel_task' => ['task' => $representation->schema($schema, 'task')],
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

    public function handle(Request $request, OwnerSubjectResolver $owners, DurableWriteCoordinator $writes, WorkToolService $work, TeamWorkPolicy $policy): Response|ResponseFactory
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
                'brief' => ['required', 'string', 'min:1', 'max:32000'],
                'content' => ['required', 'string', 'min:1', 'max:8000'],
                'role' => ['required', 'string', 'min:1', 'max:64'],
                'task_id', 'item_id' => [($field === 'item_id' && $this->name() === 'steer') || $this->name() === 'list_work' ? 'sometimes' : 'required', 'required', 'string', 'uuid'],
                'failover' => ['sometimes', 'required', 'boolean'],
                'pool_work' => ['required', 'boolean'],
                'user_actor_address', 'owner_actor' => ['sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
                'timebox_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
                'required_capabilities' => ['sometimes', 'array', 'list', 'max:25'],
                'depends_on' => ['sometimes', 'array', 'list', 'max:25'],
                'priority' => ['sometimes', 'required', Rule::enum(WorkPriority::class)],
                'expires_at' => ['sometimes', 'required', 'date'],
                'pr' => ['sometimes', 'required', 'array'],
                'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:25'],
                'state' => ['sometimes', 'required', Rule::enum(WorkItemState::class)],
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
        $owner = $owners->resolve();
        $user = User::query()->find($owner->actorId);
        if (! $user instanceof User || $user->status !== 'active'
            || ! RolePolicy::canManageMembers($user->role)
            || OffboardedSubject::userIsOffboarded($owner->actorId)) {
            throw new AuthorizationException('Only an admitted Owner or Admin may manage Work.');
        }
        $team = Team::query()->find($input['team_id']);
        if (! $team instanceof Team) {
            return Response::error('Work team is unavailable.');
        }
        if ($this->name() === 'create_task') {
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
            $result = $writes->run(
                owner: $owner, tool: $this->name(), idempotencyKey: $input['idempotency_key'],
                intent: $input, targetType: 'work',
                operation: function () use ($work, $policy, $input, $team, $owner, $user): array {
                    if ($this->name() === 'set_team_work_policy') {
                        $changed = $policy->setPoolWork($team, $user, $input['pool_work'], TeamWorkPolicy::SURFACE_MCP);

                        return ['policy' => $policy->present($team), 'changed' => $changed];
                    }
                    if ($this->name() === 'create_task') {
                        $input += [
                            'failover' => (bool) $team->default_failover,
                            'required_capabilities' => [],
                            'timebox_minutes' => 30,
                            'owner_actor' => $owner->actorId,
                            'priority' => WorkPriority::P2->value,
                            'depends_on' => [],
                        ];
                    }

                    return $work->write($this->name(), $input, $owner->actorId);
                },
            );
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
        if (isset($result['item']['latest_milestone']['payload'])) {
            $result['item']['latest_milestone']['payload'] = (object) $result['item']['latest_milestone']['payload'];
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
