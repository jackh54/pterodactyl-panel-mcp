<?php

use Illuminate\Support\Facades\Route;
use PanelMcp\Http\McpController;

/*
| /mcp : the MCP endpoint (Streamable HTTP, stateless, JSON only). Owned by the MCP feature.
|
| Mounted by PanelMcpProvider::registerRootRoutes() under /mcp with ['web', 'throttle:extensions.root']
| plus EnsureExtensionIsAvailable. Requests are authenticated by an OAuth bearer token only: the web
| group (session, cookies, CSRF) is removed so a signed-in browser tab cannot drive it, and the
| per-IP throttle is replaced by the per-connection limit inside the controller (the connector's
| requests all come from the app vendor's servers).
*/

$machine = ['web', 'throttle:extensions.root'];

Route::post('/', [McpController::class, 'handle'])->withoutMiddleware($machine)->name('handle');
Route::match(['get', 'delete'], '/', [McpController::class, 'stream'])->withoutMiddleware($machine)->name('stream');
