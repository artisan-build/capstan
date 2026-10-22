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

#[Name('set_task_priority')]
#[Description('Changes root Work priority. P0 first returns a confirmation token and exact preemption inventory; confirm with a fresh idempotency key, token and bounded reason. Do not include secrets or URLs in the reason.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class SetTaskPriorityTool extends WorkTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
}
