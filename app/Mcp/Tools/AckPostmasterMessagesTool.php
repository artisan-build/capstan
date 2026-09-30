<?php

namespace App\Mcp\Tools;

use App\Features\Postmaster;
use App\Http\Controllers\Api\PollController;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Postmaster\MessageAcknowledger;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

#[Name('ack_postmaster_messages')]
#[Description('Acknowledges a bounded list of messages addressed to inboxes owned by this actor.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Write)]
final class AckPostmasterMessagesTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            'message_ids' => $schema->array()
                ->items($schema->string()->min(1)->max(255))
                ->min(1)
                ->max(PollController::MAX_ACKS)
                ->unique()
                ->required(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        DurableWriteCoordinator $writes,
        MessageAcknowledger $acknowledger,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['idempotency_key', 'message_ids']);

        if (! Feature::active(Postmaster::class)) {
            return Response::error('Postmaster is unavailable.');
        }

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'message_ids' => ['required', 'array', 'list', 'min:1', 'max:'.PollController::MAX_ACKS],
            'message_ids.*' => ['required', 'string', 'min:1', 'max:255', 'distinct:strict'],
        ]);
        $rawIds = $this->rawArgument('message_ids');

        if (! is_array($rawIds) || ! array_is_list($rawIds)) {
            throw ValidationException::withMessages(['message_ids' => ['The message_ids field must be a JSON list.']]);
        }

        /** @var list<string> $messageIds */
        $messageIds = array_values($validated['message_ids']);
        sort($messageIds, SORT_STRING);
        $owner = $owners->resolve();
        $outcome = $writes->run(
            owner: $owner,
            tool: 'ack_postmaster_messages',
            idempotencyKey: (string) $validated['idempotency_key'],
            intent: ['message_ids' => $messageIds],
            targetType: 'postmaster_ack_batch',
            operation: fn (string $targetId, string $claimId): array => $acknowledger->acknowledge($owner->actorId, $messageIds),
        );

        return ToolResponse::structured($outcome);
    }
}
