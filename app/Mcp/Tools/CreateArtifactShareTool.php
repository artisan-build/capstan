<?php

namespace App\Mcp\Tools;

use App\Enums\ArtifactVisibility;
use App\Features\Artifacts;
use App\Http\ApiErrorException;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Support\ArtifactCreator;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

#[Name('create_artifact_share')]
#[Description('Creates one private stored artifact behind Capstan isolated viewer origin.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class CreateArtifactShareTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            'content' => $schema->string()->min(1)->max((int) config('capstan.artifacts.max_content_bytes'))->required(),
            'content_type' => $schema->string()->enum($this->allowedContentTypes())->required(),
            'visibility' => $schema->string()->enum(ArtifactVisibility::class)->required(),
            'expires_at' => $schema->string(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        DurableWriteCoordinator $writes,
        ArtifactCreator $creator,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['idempotency_key', 'content', 'content_type', 'visibility', 'expires_at']);

        if (! Feature::active(Artifacts::class)) {
            return Response::error('Artifacts are unavailable.');
        }

        $maxBytes = (int) config('capstan.artifacts.max_content_bytes');
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'content' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($maxBytes): void {
                if (is_string($value) && strlen($value) > $maxBytes) {
                    $fail("The {$attribute} field exceeds the artifact byte limit.");
                }
            }],
            'content_type' => ['required', 'string', Rule::in($this->allowedContentTypes())],
            'visibility' => ['required', 'string', Rule::in(ArtifactVisibility::values())],
            'expires_at' => ['sometimes', 'required', 'string'],
        ]);
        $owner = $owners->resolve();
        $intent = [
            'content' => (string) $validated['content'],
            'content_type' => (string) $validated['content_type'],
            'visibility' => (string) $validated['visibility'],
            'expires_at' => $this->normalizeExpiry($validated['expires_at'] ?? null),
        ];
        $outcome = $writes->run(
            owner: $owner,
            tool: 'create_artifact_share',
            idempotencyKey: (string) $validated['idempotency_key'],
            intent: $intent,
            targetType: 'artifact',
            operation: function (string $targetId) use ($creator, $owner, $intent): array {
                try {
                    $artifact = $creator->create(
                        actorId: $owner->actorId,
                        content: $intent['content'],
                        contentType: $intent['content_type'],
                        visibility: ArtifactVisibility::from($intent['visibility']),
                        expiresAt: $intent['expires_at'],
                        id: $targetId,
                    );
                } catch (UniqueConstraintViolationException) {
                    throw new ApiErrorException(409, 'artifact_identity_conflict', 'The artifact identity is unavailable.');
                }

                return ['artifact' => $creator->representation($artifact)];
            },
        );

        return ToolResponse::structured($outcome);
    }

    private function normalizeExpiry(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match(
            '/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})\z/D',
            $value,
            $matches,
        ) !== 1) {
            throw ValidationException::withMessages(['expires_at' => ['The expires at field must be a valid RFC 3339 timestamp.']]);
        }

        $fraction = str_pad($matches[2] ?? '', 6, '0');
        $offset = $matches[3] === 'Z' ? '+00:00' : $matches[3];
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $matches[1].'.'.$fraction.$offset);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ValidationException::withMessages(['expires_at' => ['The expires at field must be a real date and time.']]);
        }

        $expiry = CarbonImmutable::instance($parsed)->utc();

        if (! $expiry->isFuture()) {
            throw ValidationException::withMessages(['expires_at' => ['The expires at field must be a future date.']]);
        }

        return $expiry->startOfSecond()->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @return list<string> */
    private function allowedContentTypes(): array
    {
        $types = config('capstan.artifacts.allowed_content_types');

        return is_array($types) ? array_values($types) : [];
    }
}
