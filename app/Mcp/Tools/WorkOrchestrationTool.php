<?php

namespace App\Mcp\Tools;

use App\Features\Work;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Models\Team;
use App\Work\WorkOrchestrationMcpService;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Pennant\Feature;

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
        if (! Feature::active(Work::class)) {
            return Response::error('Work is unavailable.');
        }
        $schema = $this->schema(new JsonSchemaTypeFactory);
        $this->requireExactArguments($request, array_keys($schema));
        $rules = [];
        foreach ($schema as $field => $property) {
            $raw = $this->rawArgument($field);
            if (array_key_exists($field, $request->all())) {
                $type = $property->toArray()['type'];
                $valid = $type === 'integer' ? is_int($raw) : is_string($raw);
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
                'seat_fence' => ['required', 'integer', 'min:1'],
                'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:25'],
                'answer' => ['required', 'string', 'min:1', 'max:10000'],
                'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
                default => throw new \LogicException('Unknown Work orchestration input.'),
            };
        }
        $input = $request->validate($rules);
        $owner = $owners->resolve();
        $user = User::query()->find($owner->actorId);
        if (! $user instanceof User || $user->status !== 'active' || ! RolePolicy::canManageMembers($user->role)
            || OffboardedSubject::userIsOffboarded($owner->actorId)) {
            throw new JsonRpcException('Only an admitted Owner or Admin may manage Work.', -32001);
        }
        if (! Team::query()->whereKey($input['team_id'])->exists()) {
            return Response::error('Work team is unavailable.');
        }
        if ($owner->credentialId === null) {
            throw new JsonRpcException('This MCP principal is not supported.', -32001);
        }

        if ($this->isWrite()) {
            $result = $writes->run(
                owner: $owner,
                tool: $this->name(),
                idempotencyKey: $input['idempotency_key'],
                intent: $input,
                targetType: 'work_orchestration',
                operation: fn (): array => match ($this->name()) {
                    'take_seat' => $work->take($input['team_id'], $input['task_id'], $owner->actorId, $owner->credentialId, $input['spoke_id'] ?? null, $input['session_ref'], $input['after'] ?? 0, CarbonImmutable::now()),
                    'release_seat' => $work->release($input['team_id'], $input['task_id'], $owner->actorId, $owner->credentialId, $input['spoke_id'] ?? null, $input['seat_fence'], CarbonImmutable::now()),
                    'answer_escalation' => $work->answer($input['team_id'], $input['escalation_id'], $owner->actorId, $input['answer'], CarbonImmutable::now()),
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
