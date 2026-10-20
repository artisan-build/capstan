<?php

namespace App\Mcp\Tools;

use App\Features\Postmaster;
use App\Machine\MachineBudgetProjection;
use App\Mcp\OwnerSubjectResolver;
use App\Mcp\ToolResponse;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

#[Name('machine_budgets')]
#[Description('Returns the selected team machine map and per-person inference headroom routing aid.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class MachineBudgetsTool extends CapstanTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;

    public function schema(JsonSchema $schema): array
    {
        return ['team_id' => $schema->integer()->min(1)->required()];
    }

    public function handle(
        Request $request,
        OwnerSubjectResolver $owners,
        MachineBudgetProjection $projection,
    ): Response|ResponseFactory {
        $this->requireExactArguments($request, ['team_id']);
        if (! Feature::active(Postmaster::class)) {
            return Response::error('Machine budgets are unavailable.');
        }

        $validated = $request->validate(['team_id' => ['required', 'integer', 'min:1']]);
        $owner = $owners->resolve();
        $viewer = User::query()->findOrFail($owner->actorId);
        $result = $projection->forTeam($viewer, (int) $validated['team_id'], CarbonImmutable::now());

        return ToolResponse::fits($result)
            ? ToolResponse::structured($result)
            : Response::error('The machine budget result exceeds the response budget.');
    }
}
