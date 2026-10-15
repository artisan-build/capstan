<?php

namespace App\Mcp\Tools;

use App\Enums\PageLayout;
use App\Features\Artifacts;
use App\Http\ApiErrorException;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Support\PagePublisher;
use App\Support\PageRepresentation;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
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

#[Name('publish_page')]
#[Description('Publishes a private persistent page with its first immutable HTML version and refresh recipe.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class PublishPageTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            'title' => $schema->string()->min(1)->max(255)->required(),
            'description' => $schema->string()->max((int) config('capstan.pages.max_metadata_bytes')),
            'recipe' => $schema->string()->min(1)->max((int) config('capstan.pages.max_metadata_bytes'))->required(),
            'layout' => $schema->string()->enum(PageLayout::class)->required(),
            'content' => $schema->string()->min(1)->max((int) config('capstan.pages.max_content_bytes'))->required(),
            'content_type' => $schema->string()->enum($this->allowedContentTypes())->required(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        DurableWriteCoordinator $writes,
        PagePublisher $publisher,
        PageRepresentation $representation,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, [
            'idempotency_key', 'title', 'description', 'recipe', 'layout', 'content', 'content_type',
        ]);

        if (! Feature::active(Artifacts::class)) {
            return Response::error('Pages are unavailable.');
        }

        $metadataMaxBytes = (int) config('capstan.pages.max_metadata_bytes');
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535', $this->byteLimit($metadataMaxBytes, 'metadata')],
            'recipe' => ['required', 'string', 'min:1', 'max:65535', $this->byteLimit($metadataMaxBytes, 'metadata')],
            'layout' => ['required', 'string', Rule::enum(PageLayout::class)],
            'content' => $this->contentRules(),
            'content_type' => ['required', 'string', Rule::in($this->allowedContentTypes())],
        ]);
        $owner = $owners->resolve();
        $intent = [
            'title' => (string) $validated['title'],
            'description' => array_key_exists('description', $validated) ? (string) ($validated['description'] ?? '') : null,
            'recipe' => (string) $validated['recipe'],
            'layout' => (string) $validated['layout'],
            'content' => (string) $validated['content'],
            'content_type' => (string) $validated['content_type'],
        ];

        if (! $representation->proposedDetailFits(
            ownerUserId: $owner->actorId,
            title: $intent['title'],
            description: $intent['description'],
            recipe: $intent['recipe'],
            layout: PageLayout::from($intent['layout']),
            content: $intent['content'],
            contentType: $intent['content_type'],
            publisherTool: 'publish_page',
        )) {
            throw ValidationException::withMessages(['content' => ['The page exceeds the MCP response budget.']]);
        }

        $outcome = $writes->run(
            owner: $owner,
            tool: 'publish_page',
            idempotencyKey: (string) $validated['idempotency_key'],
            intent: $intent,
            targetType: 'page',
            operation: function (string $targetId) use ($owner, $publisher, $representation, $intent): array {
                try {
                    $page = $publisher->publish(
                        pageId: $targetId,
                        ownerUserId: $owner->actorId,
                        title: $intent['title'],
                        description: $intent['description'],
                        refreshRecipe: $intent['recipe'],
                        layout: PageLayout::from($intent['layout']),
                        content: $intent['content'],
                        contentType: $intent['content_type'],
                    );
                } catch (UniqueConstraintViolationException) {
                    throw new ApiErrorException(409, 'page_identity_conflict', 'The page identity is unavailable.');
                }

                return ['page' => $representation->summary($page)];
            },
        );

        return ToolResponse::structured($outcome);
    }

    /** @return list<mixed> */
    private function contentRules(): array
    {
        $maxBytes = (int) config('capstan.pages.max_content_bytes');

        return ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($maxBytes): void {
            if (is_string($value) && strlen($value) > $maxBytes) {
                $fail("The {$attribute} field exceeds the page byte limit.");
            }
        }];
    }

    private function byteLimit(int $maxBytes, string $label): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($maxBytes, $label): void {
            if (is_string($value) && strlen($value) > $maxBytes) {
                $fail("The {$attribute} field exceeds the page {$label} byte limit.");
            }
        };
    }

    /** @return list<string> */
    private function allowedContentTypes(): array
    {
        $types = config('capstan.pages.allowed_content_types');

        return is_array($types) ? array_values($types) : [];
    }
}
