<?php

namespace App\Mcp\Tools;

use App\Enums\PageLayout;
use App\Features\Artifacts;
use App\Http\ApiErrorException;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Models\Page;
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

#[Name('update_page')]
#[Description('Appends one immutable HTML version to an owned page and optionally replaces its current metadata.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class UpdatePageTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            'page_id' => $schema->string()->required(),
            'title' => $schema->string()->min(1)->max(255),
            'description' => $schema->string()->max((int) config('capstan.pages.max_metadata_bytes')),
            'recipe' => $schema->string()->min(1)->max((int) config('capstan.pages.max_metadata_bytes')),
            'layout' => $schema->string()->enum(PageLayout::class),
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
            'idempotency_key', 'page_id', 'title', 'description', 'recipe', 'layout', 'content', 'content_type',
        ]);

        if (! Feature::active(Artifacts::class)) {
            return Response::error('Pages are unavailable.');
        }

        $maxBytes = (int) config('capstan.pages.max_content_bytes');
        $metadataMaxBytes = (int) config('capstan.pages.max_metadata_bytes');
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'page_id' => ['required', 'string', 'uuid'],
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535', $this->byteLimit($metadataMaxBytes, 'metadata')],
            'recipe' => ['sometimes', 'required', 'string', 'min:1', 'max:65535', $this->byteLimit($metadataMaxBytes, 'metadata')],
            'layout' => ['sometimes', 'required', 'string', Rule::enum(PageLayout::class)],
            'content' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($maxBytes): void {
                if (is_string($value) && strlen($value) > $maxBytes) {
                    $fail("The {$attribute} field exceeds the page byte limit.");
                }
            }],
            'content_type' => ['required', 'string', Rule::in($this->allowedContentTypes())],
        ]);
        $owner = $owners->resolve();
        $pageId = (string) $validated['page_id'];

        $ownedPage = Page::query()->whereKey($pageId)->where('owner_user_id', $owner->actorId)->first();

        if (! $ownedPage instanceof Page) {
            return ToolResponse::structured(['outcome' => 'refused', 'reason' => 'page_not_found']);
        }

        $intent = [
            'page_id' => $pageId,
            'content' => (string) $validated['content'],
            'content_type' => (string) $validated['content_type'],
        ];

        foreach (['title', 'recipe', 'layout'] as $field) {
            if (array_key_exists($field, $validated)) {
                $intent[$field] = (string) $validated[$field];
            }
        }

        if (array_key_exists('description', $validated)) {
            $intent['description'] = (string) ($validated['description'] ?? '');
        }

        $finalLayout = array_key_exists('layout', $intent)
            ? PageLayout::from($intent['layout'])
            : $ownedPage->layout;

        if (! $representation->proposedDetailFits(
            ownerUserId: $owner->actorId,
            title: $intent['title'] ?? $ownedPage->title,
            description: array_key_exists('description', $intent) ? $intent['description'] : $ownedPage->description,
            recipe: $intent['recipe'] ?? $ownedPage->refresh_recipe,
            layout: $finalLayout,
            content: $intent['content'],
            contentType: $intent['content_type'],
            publisherTool: 'update_page',
        )) {
            throw ValidationException::withMessages(['content' => ['The page exceeds the MCP response budget.']]);
        }

        $outcome = $writes->run(
            owner: $owner,
            tool: 'update_page',
            idempotencyKey: (string) $validated['idempotency_key'],
            intent: $intent,
            targetType: 'page_version',
            operation: function (string $targetId) use ($owner, $publisher, $representation, $intent): array {
                $replacements = array_intersect_key($intent, array_flip(['title', 'description', 'recipe', 'layout']));

                if (array_key_exists('recipe', $replacements)) {
                    $replacements['refresh_recipe'] = $replacements['recipe'];
                    unset($replacements['recipe']);
                }

                if (array_key_exists('layout', $replacements)) {
                    $replacements['layout'] = PageLayout::from($replacements['layout']);
                }

                try {
                    $page = $publisher->update(
                        pageId: $intent['page_id'],
                        ownerUserId: $owner->actorId,
                        versionId: $targetId,
                        replacements: $replacements,
                        content: $intent['content'],
                        contentType: $intent['content_type'],
                    );
                } catch (UniqueConstraintViolationException) {
                    throw new ApiErrorException(409, 'page_version_identity_conflict', 'The page version identity is unavailable.');
                }

                return ['page' => $representation->summary($page)];
            },
        );

        return ToolResponse::structured($outcome);
    }

    /** @return list<string> */
    private function allowedContentTypes(): array
    {
        $types = config('capstan.pages.allowed_content_types');

        return is_array($types) ? array_values($types) : [];
    }

    private function byteLimit(int $maxBytes, string $label): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($maxBytes, $label): void {
            if (is_string($value) && strlen($value) > $maxBytes) {
                $fail("The {$attribute} field exceeds the page {$label} byte limit.");
            }
        };
    }
}
