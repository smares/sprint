<?php

use App\Http\Middleware\EnsureMcpToken;
use App\Mcp\Servers\SprintServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', SprintServer::class)
    ->middleware(['auth:sanctum', EnsureMcpToken::class, 'throttle:mcp']);
