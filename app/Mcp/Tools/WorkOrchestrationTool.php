<?php

namespace App\Mcp\Tools;

use App\Http\ApiErrorException;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Mcp\UnsupportedMcpPrincipal;
use App\Models\Team;
use App\Work\WorkOrchestrationMcpService;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class WorkOrchestrationTool extends CapstanTool
{
    public function schema(JsonSchema $schema): array
    {
        return match ($this->name()) {
            'take_seat' => [
                'team_id' => $schema->integer()->min(1)->required(),
                'task_id' => $schema->string()->required(),
                'spoke_id' => $schema->integer()->min(1),
                'session_ref' => $schema->string()->min(1)->max(2048)->required(),
                'after' => $schema->integer()->min(0)->default(0),
                'decisions_after' => $schema->string(),
                'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            ],
            'release_seat' => [
                'team_id' => $schema->integer()->min(1)->required(),
                'task_id' => $schema->string()->required(),
                'spoke_id' => $schema->integer()->min(1),
                'seat_fence' => $schema->integer()->min(1)->required(),
                'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            ],
            'list_escalations' => [
                'team_id' => $schema->integer()->min(1)->required(),
                'limit' => $schema->integer()->min(1)->max(25)->default(25),
            ],
            'answer_escalation' => [
                'team_id' => $schema->integer()->min(1)->required(),
                'escalation_id' => $schema->string()->required(),
                'answer' => $schema->string()->min(1)->max(10000)->required(),
                'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            ],
            'orchestrate' => [
                'team_id' => $schema->integer()->min(1)->required(),
                'task_id' => $schema->string()->required(),
                'spoke_id' => $schema->integer()->min(1),
                'seat_fence' => $schema->integer()->min(1)->required(),
                'session_ref' => $schema->string()->min(1)->max(2048)->required(),
                'action' => $schema->string()->enum(['create_child', 'set_priority', 'set_pr', 'revise_brief', 'queue_item', 'cancel_item', 'steer', 'decide', 'request_wake', 'escalate', 'finish'])->required(),
                'input' => $schema->object([
                    'under_task_id' => $schema->string(), 'task_id' => $schema->string(), 'item_id' => $schema->string(), 'event_id' => $schema->string(),
                    'title' => $schema->string()->min(1)->max(255), 'brief' => $schema->string()->min(1)->max(32000), 'role' => $schema->string()->min(1)->max(64), 'content' => $schema->string()->min(1)->max(100000),
                    'decision' => $schema->string()->min(1)->max(10000), 'rationale' => $schema->string()->min(1)->max(10000), 'evidence' => $schema->object(),
                    'key' => $schema->string()->min(1)->max(255), 'due_at' => $schema->string()->min(1), 'reason' => $schema->string()->min(1)->max(2000),
                    'addressed_actor' => $schema->string()->min(1)->max(19), 'question' => $schema->string()->min(1)->max(10000), 'options' => $schema->array()->items($schema->string()->min(1)->max(2000))->min(1)->max(10), 'recommendation' => $schema->string()->min(1)->max(2000),
                    'state' => $schema->string()->enum(['acted', 'failed']), 'user_actor_address' => $schema->string()->min(1)->max(19), 'device_spoke_id' => $schema->integer()->min(1),
                    'failover' => $schema->boolean(), 'required_capabilities' => $schema->array()->items($schema->string()->min(1)->max(64))->max(25)->unique(),
                    'timebox_minutes' => $schema->integer()->min(1)->max(1440), 'workflow_ref' => $schema->string()->max(2048),
                    'owner_actor' => $schema->string()->min(1)->max(19), 'priority' => $schema->string()->enum(['P0', 'P1', 'P2', 'P3']),
                    'depends_on' => $schema->array()->items($schema->string())->max(25)->unique(), 'expires_at' => $schema->string(),
                    'pr' => $schema->object([
                        'repo' => $schema->string()->min(1)->max(255)->required(),
                        'number' => $schema->integer()->min(1)->required(),
                        'url' => $schema->string()->min(1)->max(2048)->required(),
                    ])->withoutAdditionalProperties(),
                ])->withoutAdditionalProperties()->required(),
                'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            ],
            default => throw new \LogicException('Unknown Work orchestration tool.'),
        };
    }

    public function outputSchema(JsonSchema $schema): array
    {
        $event = $schema->object([
            'id' => $schema->string()->required(),
            'sequence' => $schema->integer()->required(),
            'kind' => $schema->string()->required(),
            'evidence' => $schema->string()->required(),
        ])->withoutAdditionalProperties();
        $escalation = $schema->object([
            'id' => $schema->string()->required(),
            'task_id' => $schema->string()->required(),
            'item_id' => $schema->string()->required(),
            'question' => $schema->string()->required(),
            'options' => $schema->array()->items($schema->string())->required(),
            'recommendation' => $schema->string()->required(),
            'answer' => $schema->string(),
            'answered_at' => $schema->string(),
            'created_at' => $schema->string()->required(),
        ])->withoutAdditionalProperties();
        $task = $schema->object([
            'id' => $schema->string()->required(), 'team_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(), 'workflow_ref' => $schema->string(),
            'owner_actor' => $schema->string()->required(),
            'escalation_actor' => $schema->string()->required(),
            'priority' => $schema->string()->enum(['P0', 'P1', 'P2', 'P3'])->required(),
            'depends_on' => $schema->array()->items($schema->string())->required(),
            'expires_at' => $schema->string(),
            'pr' => $schema->object([
                'repo' => $schema->string()->required(),
                'number' => $schema->integer()->required(),
                'url' => $schema->string()->required(),
            ])->withoutAdditionalProperties(),
        ])->withoutAdditionalProperties();
        $brief = $schema->object(['id' => $schema->string()->required(), 'sequence' => $schema->integer()->required(), 'content' => $schema->string()->required()])->withoutAdditionalProperties();
        $state = $schema->object(['id' => $schema->string()->required(), 'sequence' => $schema->integer()->required(), 'through' => $schema->integer()->required(), 'content' => $schema->string()->required()])->withoutAdditionalProperties();
        $decision = $schema->object([
            'id' => $schema->string()->required(), 'event_id' => $schema->string()->required(), 'decision' => $schema->string()->required(),
            'rationale' => $schema->string()->required(), 'evidence' => $schema->string()->required(),
        ])->withoutAdditionalProperties();
        $answeredEscalation = $schema->object([
            'id' => $schema->string()->required(), 'question' => $schema->string()->required(),
            'options' => $schema->array()->items($schema->string())->required(),
            'answer' => $schema->string()->required(), 'answered_at' => $schema->string()->required(),
        ])->withoutAdditionalProperties();
        $context = $schema->object([
            'task' => $task->required(), 'brief' => $brief, 'state' => $state,
            'decisions' => $schema->array()->items($decision)->required(), 'events' => $schema->array()->items($event)->required(),
            'answered_escalations' => $schema->array()->items($answeredEscalation)->required(),
            'cursor' => $schema->integer()->required(), 'next_cursor' => $schema->integer(),
            'through' => $schema->integer()->required(), 'has_more' => $schema->boolean()->required(),
            'decisions_cursor' => $schema->string(), 'decisions_next_cursor' => $schema->string(),
            'decisions_has_more' => $schema->boolean()->required(),
        ])->withoutAdditionalProperties();

        $properties = match ($this->name()) {
            'take_seat' => [
                'seat' => $schema->object([
                    'task_id' => $schema->string()->required(),
                    'seat_fence' => $schema->integer()->required(),
                    'lease_until' => $schema->string()->required(),
                    'session_ref' => $schema->string()->required(),
                    'cursor' => $schema->integer()->required(),
                    'through' => $schema->integer()->required(),
                    'spoke_id' => $schema->integer()->required(),
                ])->withoutAdditionalProperties(),
                'events' => $schema->array()->items($event),
                'has_more' => $schema->boolean(),
                'context' => $context,
            ],
            'release_seat' => [
                'released' => $schema->boolean(),
                'task_id' => $schema->string(),
                'seat_fence' => $schema->integer(),
            ],
            'list_escalations' => [
                'escalations' => $schema->array()->items($escalation)->required(),
                'limit' => $schema->integer()->min(1)->max(25)->required(),
                'has_more' => $schema->boolean()->required(),
            ],
            'answer_escalation' => ['escalation' => $escalation],
            'orchestrate' => [
                'action' => $schema->string(), 'status' => $schema->string(), 'id' => $schema->string(),
                'task_id' => $schema->string(), 'item_id' => $schema->string(),
            ],
            default => throw new \LogicException('Unknown Work orchestration tool.'),
        };
        if ($this->isWrite()) {
            $properties['outcome'] = $schema->string()->enum(['accepted', 'already_accepted', 'refused', 'in_progress', 'outcome_unknown'])->required();
            $properties['reason'] = $schema->string();
        }

        return $properties;
    }

    public function handle(Request $request, OwnerSubjectResolver $owners, DurableWriteCoordinator $writes, WorkOrchestrationMcpService $work): Response|ResponseFactory
    {
        $schema = $this->schema(new JsonSchemaTypeFactory);
        $this->requireExactArguments($request, array_keys($schema));
        $rules = [];
        foreach ($schema as $field => $property) {
            $raw = $this->rawArgument($field);
            if (array_key_exists($field, $request->all())) {
                $type = $property->toArray()['type'];
                $valid = match ($type) {
                    'integer' => is_int($raw), 'object' => $raw instanceof \stdClass,
                    default => is_string($raw),
                };
                if (! $valid) {
                    throw ValidationException::withMessages([$field => ['Invalid argument type.']]);
                }
            }
            $rules[$field] = match ($field) {
                'team_id' => ['required', 'integer', 'min:1'],
                'spoke_id' => ['sometimes', 'required', 'integer', 'min:1'],
                'task_id', 'escalation_id' => ['required', 'string', 'uuid'],
                'session_ref' => ['required', 'string', 'min:1', 'max:2048'],
                'after' => ['sometimes', 'required', 'integer', 'min:0'],
                'decisions_after' => ['sometimes', 'required', 'string', 'uuid'],
                'seat_fence' => ['required', 'integer', 'min:1'],
                'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:25'],
                'answer' => ['required', 'string', 'min:1', 'max:10000'],
                'action' => ['required', 'string', Rule::in(['create_child', 'set_priority', 'set_pr', 'revise_brief', 'queue_item', 'cancel_item', 'steer', 'decide', 'request_wake', 'escalate', 'finish'])],
                'input' => ['required', 'array'],
                'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
                default => throw new \LogicException('Unknown Work orchestration input.'),
            };
        }
        $input = $request->validate($rules);
        $owner = $owners->resolve();
        $user = User::query()->find($owner->actorId);
        if (! $user instanceof User || $user->status !== 'active' || ! RolePolicy::canManageMembers($user->role)
            || OffboardedSubject::userIsOffboarded($owner->actorId)) {
            throw new AuthorizationException('Only an admitted Owner or Admin may manage Work.');
        }
        if (! Team::query()->whereKey($input['team_id'])->exists()) {
            return Response::error('Work team is unavailable.');
        }
        if ($owner->credentialId === null) {
            throw new UnsupportedMcpPrincipal;
        }
        if ($this->name() === 'orchestrate') {
            try {
                $work->validateAction($input['action'], $input['input']);
            } catch (ApiErrorException $exception) {
                return ToolResponse::structured(['outcome' => 'refused', 'reason' => $exception->errorCode]);
            }
        }

        if ($this->isWrite()) {
            $result = $writes->run(
                owner: $owner,
                tool: $this->name(),
                idempotencyKey: $input['idempotency_key'],
                intent: $input,
                targetType: 'work_orchestration',
                operation: fn (): array => match ($this->name()) {
                    'take_seat' => $work->take($input['team_id'], $input['task_id'], $owner->actorId, $owner->credentialId, $input['spoke_id'] ?? null, $input['session_ref'], $input['after'] ?? 0, CarbonImmutable::now(), $input['decisions_after'] ?? null, $owner->credentialBacked),
                    'release_seat' => $work->release($input['team_id'], $input['task_id'], $owner->actorId, $owner->credentialId, $input['spoke_id'] ?? null, $input['seat_fence'], CarbonImmutable::now(), $owner->credentialBacked),
                    'answer_escalation' => $work->answer($input['team_id'], $input['escalation_id'], $owner->actorId, $input['answer'], CarbonImmutable::now()),
                    'orchestrate' => $work->orchestrate($input['team_id'], $input['task_id'], $owner->actorId, $owner->credentialId, $input['spoke_id'] ?? null, $input['seat_fence'], $input['session_ref'], $input['action'], $input['input'], CarbonImmutable::now(), $owner->credentialBacked),
                    default => throw new \LogicException('Unknown Work orchestration write.'),
                },
            );
        } else {
            $result = $work->escalations($input['team_id'], $owner->actorId, $input['limit'] ?? 25);
        }

        if (! ToolResponse::fits($result)) {
            return Response::error('Work result exceeds the response budget.');
        }

        return ToolResponse::structured($result);
    }

    private function isWrite(): bool
    {
        return $this->name() !== 'list_escalations';
    }
}
