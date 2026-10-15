<?php

namespace App\Mcp;

use App\Mcp\Tools\AckPostmasterMessagesTool;
use App\Mcp\Tools\CreateArtifactShareTool;
use App\Mcp\Tools\GetPageTool;
use App\Mcp\Tools\ListPagesTool;
use App\Mcp\Tools\PostmasterMessagesTool;
use App\Mcp\Tools\PostmasterSpokesTool;
use App\Mcp\Tools\PublishPageTool;
use App\Mcp\Tools\SendPostmasterMessageTool;
use App\Mcp\Tools\UpdatePageTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Capstan')]
#[Version('1.0.0')]
#[Instructions('Read and operate the authenticated actor own Capstan data. When the user asks for a UI, report, dashboard, or brief, publish it as a page and include a refresh recipe that explains how to rebuild it.')]
final class CapstanServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        PostmasterSpokesTool::class,
        PostmasterMessagesTool::class,
        SendPostmasterMessageTool::class,
        AckPostmasterMessagesTool::class,
        CreateArtifactShareTool::class,
        PublishPageTool::class,
        UpdatePageTool::class,
        ListPagesTool::class,
        GetPageTool::class,
    ];
}
