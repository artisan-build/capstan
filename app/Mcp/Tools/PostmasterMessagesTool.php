<?php

namespace App\Mcp\Tools;

use App\Features\Postmaster;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\SignedCursor;
use App\Mcp\ToolResponse;
use App\Models\Envelope;
use App\Models\Inbox;
use App\Support\Address;
use App\Support\ServerIdentity;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

#[Name('postmaster_messages')]
#[Description('Reads one owned local inbox without acknowledging messages or changing delivery state.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class PostmasterMessagesTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public const int MAX_LIMIT = 50;

    public function schema(JsonSchema $schema): array
    {
        return [
            'inbox' => $schema->string()->min(1)->max(64)->required(),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(20),
            'cursor' => $schema->string()->max(2048),
        ];
    }

    public function handle(Request $request, OwnerSubjectResolver $owners, SignedCursor $cursors, ServerIdentity $identity): Response|ResponseFactory
    {
        $this->requireExactArguments($request, ['inbox', 'limit', 'cursor']);

        if (! Feature::active(Postmaster::class)) {
            return Response::error('Postmaster is unavailable.');
        }

        $validated = $request->validate([
            'inbox' => ['required', 'string', 'max:64'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'required', 'string', 'max:2048'],
        ]);

        if (! Address::isValidLocalPart($validated['inbox'])) {
            throw new JsonRpcException('The inbox is unavailable.', -32004);
        }

        $owner = $owners->resolve();
        $inbox = Inbox::query()->where('actor_id', $owner->actorId)->where('local_part', $validated['inbox'])->first();

        if (! $inbox instanceof Inbox) {
            throw new JsonRpcException('The inbox is unavailable.', -32004);
        }

        $limit = (int) ($validated['limit'] ?? 20);
        $actorBinding = $owner->type.':'.$owner->ref;
        $queryBinding = hash('sha256', 'postmaster_messages:v1:'.$inbox->local_part.':'.$limit);
        $after = isset($validated['cursor'])
            ? $cursors->decode($validated['cursor'], $actorBinding, $queryBinding)
            : null;
        $query = Envelope::query()
            ->where('to_server_id', $identity->id())
            ->where('to_local_part', $inbox->local_part)
            ->oldest('received_at')
            ->orderBy('id');

        if ($after !== null) {
            $receivedAt = $after['received_at'] ?? null;
            $id = $after['id'] ?? null;

            if (! is_string($receivedAt) || ! is_string($id)) {
                throw new JsonRpcException('The cursor is invalid for this request.', -32602);
            }

            $query->where(function ($query) use ($receivedAt, $id): void {
                $query->where('received_at', '>', $receivedAt)
                    ->orWhere(function ($query) use ($receivedAt, $id): void {
                        $query->where('received_at', $receivedAt)->where('id', '>', $id);
                    });
            });
        }

        $candidates = $query->limit($limit + 1)->get();
        $items = [];
        $consumed = [];

        foreach ($candidates as $message) {
            if (count($items) >= $limit) {
                break;
            }

            $item = $this->message($message);

            if (! ToolResponse::fits(['items' => [...$items, $item], 'next_cursor' => str_repeat('x', 2048)])) {
                $item = $this->omittedMessage($message);

                if (! ToolResponse::fits(['items' => [...$items, $item], 'next_cursor' => str_repeat('x', 2048)])) {
                    break;
                }
            }

            $items[] = $item;
            $consumed[] = $message;
        }

        $hasMore = count($consumed) < $candidates->count() || $candidates->count() > $limit;
        $last = $consumed[array_key_last($consumed)] ?? null;
        $nextCursor = $hasMore && $last instanceof Envelope
            ? $cursors->encode([
                'actor' => $actorBinding,
                'query' => $queryBinding,
                'received_at' => $last->received_at?->format('Y-m-d H:i:s'),
                'id' => $last->id,
            ])
            : null;

        return ToolResponse::structured([
            'items' => $items,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'limit' => $limit,
        ]);
    }

    /** @return array<string, mixed> */
    private function message(Envelope $message): array
    {
        return [
            ...$message->signablePayload(),
            'signature' => $message->signature,
            'status' => $message->status->value,
            'received_at' => $message->received_at?->toJSON(),
            'delivered_at' => $message->delivered_at?->toJSON(),
            'acked_at' => $message->acked_at?->toJSON(),
            'content_omitted' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function omittedMessage(Envelope $message): array
    {
        return [
            'id' => $message->id,
            'message_id' => $message->message_id,
            'status' => $message->status->value,
            'received_at' => $message->received_at?->toJSON(),
            'delivered_at' => $message->delivered_at?->toJSON(),
            'acked_at' => $message->acked_at?->toJSON(),
            'content_omitted' => true,
            'omission_reason' => 'message_exceeds_response_budget',
        ];
    }
}
