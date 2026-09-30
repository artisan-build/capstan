<?php

namespace App\Mcp\Tools;

use App\Enums\MessageType;
use App\Features\Postmaster;
use App\Http\ApiErrorException;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Models\Envelope;
use App\Postmaster\MessageSender;
use App\Support\Address;
use App\Support\JsonCanonicalizer;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;
use stdClass;
use Throwable;

#[Name('send_postmaster_message')]
#[Description('Signs and durably stores one Postmaster message from an inbox owned by this actor.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class SendPostmasterMessageTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public const int MAX_BODY_BYTES = 262_144;

    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            'from' => $schema->string()->min(1)->max(255)->required(),
            'to' => $schema->string()->min(1)->max(255)->required(),
            'type' => $schema->string()->enum(MessageType::class)->required(),
            'version' => $schema->integer()->enum([Envelope::CURRENT_VERSION])->required(),
            'message_id' => $schema->string()->min(1)->max(255)->required(),
            'body' => $schema->object()->required(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        DurableWriteCoordinator $writes,
        MessageSender $sender,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['idempotency_key', 'from', 'to', 'type', 'version', 'message_id', 'body']);

        if (! Feature::active(Postmaster::class)) {
            return Response::error('Postmaster is unavailable.');
        }

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'from' => ['required', 'string', 'max:255', $this->addressRule()],
            'to' => ['required', 'string', 'max:255', $this->addressRule()],
            'type' => ['required', 'string', Rule::in(MessageType::values())],
            'version' => ['required', 'integer', Rule::in([Envelope::CURRENT_VERSION])],
            'message_id' => ['required', 'string', 'min:1', 'max:255'],
            'body' => ['required', 'array'],
        ]);
        $body = $this->rawObjectArgument('body');

        if (! $body instanceof stdClass) {
            throw ValidationException::withMessages(['body' => ['The body field must be a JSON object.']]);
        }

        try {
            $bodyBytes = strlen(JsonCanonicalizer::encode($body));
        } catch (Throwable) {
            throw ValidationException::withMessages(['body' => ['The body field must contain canonical JSON values.']]);
        }

        if ($bodyBytes > self::MAX_BODY_BYTES) {
            throw ValidationException::withMessages(['body' => ['The body field exceeds the message byte limit.']]);
        }

        $owner = $owners->resolve();
        $message = [
            'from' => (string) $validated['from'],
            'to' => (string) $validated['to'],
            'type' => (string) $validated['type'],
            'version' => (int) $validated['version'],
            'message_id' => (string) $validated['message_id'],
            'body' => $body,
        ];
        $outcome = $writes->run(
            owner: $owner,
            tool: 'send_postmaster_message',
            idempotencyKey: (string) $validated['idempotency_key'],
            intent: $message,
            targetType: 'postmaster_message',
            operation: function (string $targetId, string $claimId) use ($sender, $owner, $message): array {
                $now = CarbonImmutable::now()->utc()->startOfSecond();
                $envelope = new Envelope([
                    'id' => $targetId,
                    'type' => MessageType::from($message['type']),
                    'version' => $message['version'],
                    'from_address' => $message['from'],
                    'to_address' => $message['to'],
                    'body' => $message['body'],
                    'refs' => [],
                    'message_id' => $message['message_id'],
                    'signature' => '',
                ]);
                $envelope->created_at = $now;

                if (! $sender->send($owner->actorId, $envelope, $now)) {
                    throw new ApiErrorException(409, 'message_identity_conflict', 'The message identity is unavailable.');
                }

                return [
                    'message' => [
                        'id' => $envelope->id,
                        'message_id' => $envelope->message_id,
                        'from' => $envelope->from_address,
                        'to' => $envelope->to_address,
                        'type' => $envelope->type->value,
                        'version' => $envelope->version,
                        'status' => $envelope->status->value,
                        'created_at' => $envelope->created_at->toJSON(),
                        'signature' => $envelope->signature,
                    ],
                ];
            },
        );

        return ToolResponse::structured($outcome);
    }

    /** @return \Closure(string, mixed, \Closure(string): void): void */
    private function addressRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            try {
                Address::parse($value);
            } catch (InvalidArgumentException) {
                $fail("The {$attribute} field must be a valid Postmaster address.");
            }
        };
    }
}
