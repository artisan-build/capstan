<?php

namespace App\Mcp;

use Illuminate\Auth\Access\AuthorizationException;

final class UnsupportedMcpPrincipal extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct('This MCP principal is not supported.');
    }
}
