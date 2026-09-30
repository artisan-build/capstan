<?php

use App\Mcp\CapstanServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', CapstanServer::class)
    ->middleware('bfc.mcp:product,read');

Mcp::web('/mcp/write', CapstanServer::class)
    ->middleware('bfc.mcp:product,write');
