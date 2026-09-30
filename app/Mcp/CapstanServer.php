<?php

namespace App\Mcp;

use App\Mcp\Tools\PostmasterMessagesTool;
use App\Mcp\Tools\PostmasterSpokesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Capstan')]
#[Version('1.0.0')]
#[Instructions('Read the authenticated actor own Capstan Postmaster spokes, inboxes, and messages.')]
final class CapstanServer extends Server
{
    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
        PostmasterSpokesTool::class,
        PostmasterMessagesTool::class,
    ];
}
