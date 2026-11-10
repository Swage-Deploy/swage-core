<?php

use App\Http\Middleware\EnsureReadOnlyApiKey;
use App\Mcp\Servers\SwageServer;
use Laravel\Mcp\Facades\Mcp;

/*
| The read-only MCP server AI assistants connect to, with an API key that can only read.
*/

Mcp::web('mcp', SwageServer::class)->middleware(['auth:sanctum', 'throttle:api', EnsureReadOnlyApiKey::class])->name('mcp');
