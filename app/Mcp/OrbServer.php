<?php

namespace App\Mcp;

use App\Mcp\Tools\OrbWorkBriefTool;
use App\Mcp\Tools\OrbWorkFinishedTool;
use App\Mcp\Tools\OrbWorkProgressTool;
use App\Mcp\Tools\OrbWorkStartedTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Capstan Orb Work')]
#[Version('1.0.0')]
#[Instructions('Read and update only the Work claim bound to the signed Amp workload identity.')]
final class OrbServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        OrbWorkBriefTool::class,
        OrbWorkStartedTool::class,
        OrbWorkProgressTool::class,
        OrbWorkFinishedTool::class,
    ];
}
