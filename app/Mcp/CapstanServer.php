<?php

namespace App\Mcp;

use App\Mcp\Tools\AckPostmasterMessagesTool;
use App\Mcp\Tools\PostmasterMessagesTool;
use App\Mcp\Tools\PostmasterSpokesTool;
use App\Mcp\Tools\SendPostmasterMessageTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Capstan')]
#[Version('1.0.0')]
#[Instructions('Read and operate the authenticated actor own Capstan Postmaster spokes, inboxes, and messages.')]
final class CapstanServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        PostmasterSpokesTool::class,
        PostmasterMessagesTool::class,
        SendPostmasterMessageTool::class,
        AckPostmasterMessagesTool::class,
    ];
}
