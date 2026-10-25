<?php

use App\Mcp\CapstanServer;
use App\Mcp\OrbServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', CapstanServer::class)
    ->middleware('bfc.mcp:product,read');

Mcp::web('/mcp/write', CapstanServer::class)
    ->middleware('bfc.mcp:product,write');

Mcp::web('/mcp/orb', OrbServer::class)
    ->middleware(['throttle:orb', 'capstan.amp_orb']);
