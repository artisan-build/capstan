<?php

namespace App\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('reassign_task')]
#[Description('Reassigns a root Work task and its descendants to another active member of the same team.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class ReassignTaskTool extends WorkTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
}
