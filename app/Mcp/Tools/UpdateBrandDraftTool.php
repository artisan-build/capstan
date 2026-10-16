<?php

namespace App\Mcp\Tools;

use App\Enums\PageLayout;
use App\Features\Artifacts;
use App\Mcp\DurableWriteCoordinator;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use App\Support\BrandKitSchema;
use App\Support\BrandKitService;
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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;
use stdClass;

#[Name('update_brand_draft')]
#[Description('Idempotently merges validated partial values into the installation brand draft. Owner or Admin only.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class UpdateBrandDraftTool extends BrandTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        $colours = [];
        foreach (BrandKitSchema::COLOUR_TOKENS as $token) {
            $colours[$token] = $schema->string()->min(4)->max(9);
        }
        $spacing = [];
        foreach (BrandKitSchema::SPACING_TOKENS as $token) {
            $spacing[$token] = $schema->string()->min(1)->max(32);
        }
        $radius = [];
        foreach (BrandKitSchema::RADIUS_TOKENS as $token) {
            $radius[$token] = $schema->string()->min(1)->max(32);
        }
        $font = fn () => $schema->object([
            'type' => $schema->string()->enum(['stack']),
            'value' => $schema->string()->enum(BrandKitSchema::FONT_STACKS),
        ])->withoutAdditionalProperties();
        $asset = fn () => $schema->union(['string', 'null']);

        return [
            'idempotency_key' => $schema->string()->min(1)->max(255)->required(),
            'changes' => $schema->object([
                'display_name' => $schema->string()->min(1)->max(120),
                'colours' => $schema->object([
                    'light' => $schema->object($colours)->withoutAdditionalProperties(),
                    'dark' => $schema->object($colours)->withoutAdditionalProperties(),
                ])->withoutAdditionalProperties(),
                'spacing' => $schema->object($spacing)->withoutAdditionalProperties(),
                'radius' => $schema->object($radius)->withoutAdditionalProperties(),
                'fonts' => $schema->object(['heading' => $font(), 'body' => $font()])->withoutAdditionalProperties(),
                'assets' => $schema->object([
                    'logo_light' => $asset(),
                    'logo_dark' => $asset(),
                    'mark' => $asset(),
                ])->withoutAdditionalProperties(),
                'tone_notes' => $schema->string()->max(10_000),
                'default_layout' => $schema->string()->enum(PageLayout::class),
                'header_html' => $schema->string()->max(32_768),
                'footer_html' => $schema->string()->max(32_768),
                'custom_css' => $schema->string()->max(65_536),
            ])->withoutAdditionalProperties()->required(),
        ];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        DurableWriteCoordinator $writes,
        BrandKitService $brands,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['idempotency_key', 'changes']);
        if (! Feature::active(Artifacts::class)) {
            return Response::error('Branding is unavailable.');
        }

        [$owner, $actor] = $this->brandActor($owners, $brands, true);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:1', 'max:255'],
            'changes' => ['required', 'array'],
        ]);
        $rawChanges = $this->rawObjectArgument('changes');
        if (! $rawChanges instanceof stdClass || get_object_vars($rawChanges) === []) {
            throw ValidationException::withMessages(['changes' => 'The changes field must be a non-empty JSON object.']);
        }
        $changes = (array) $validated['changes'];
        $brands->validateDraftChanges($changes);

        $outcome = $writes->run(
            owner: $owner,
            tool: 'update_brand_draft',
            idempotencyKey: (string) $validated['idempotency_key'],
            intent: ['changes' => $changes],
            targetType: 'brand_draft',
            operation: fn (): array => ['draft' => $brands->updateDraft($actor, $changes)],
        );

        return ToolResponse::structured($outcome);
    }
}
