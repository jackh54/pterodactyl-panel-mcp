<?php

use Illuminate\Support\Facades\Route;
use PanelMcp\Http\ConnectionsController;
use PanelMcp\Http\OAuthController;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;

/*
| /oauth/* : the OAuth 2.1 authorization server behind /mcp. Owned by the MCP feature.
|
| Mounted by PanelMcpProvider::registerRootRoutes() under /oauth with ['web', 'throttle:extensions.root']
| plus EnsureExtensionIsAvailable. Keep the URLs stable: connected MCP clients store them.
*/

// Machine endpoints: no session, no cookies, no CSRF (public clients authenticate with PKCE or a
// token). They are called from the app vendor's servers, so many users share one IP: the per-IP
// root-route throttle is replaced by the limits these endpoints always had (per IP, per minute).
// The third throttle argument gives each endpoint its own counter; without it Laravel counts every
// unnamed throttle of an IP together (discovery GETs used to eat into the token budget).
$machine = ['web', 'throttle:extensions.root'];

Route::post('/register', [OAuthController::class, 'register'])
    ->withoutMiddleware($machine)->middleware('throttle:20,1,panel-mcp-oauth-register')->name('register');
Route::post('/token', [OAuthController::class, 'token'])
    ->withoutMiddleware($machine)->middleware('throttle:60,1,panel-mcp-oauth-token')->name('token');
Route::post('/revoke', [OAuthController::class, 'revoke'])
    ->withoutMiddleware($machine)->middleware('throttle:60,1,panel-mcp-oauth-revoke')->name('revoke');

// Browser pages: a normal signed-in panel session (2FA rules included) and CSRF protection.
// A signed-out visitor is sent to /auth/login with this URL kept as url.intended, and comes back
// here after signing in (PanelMcp\Auth\IntendedLoginRedirect).
Route::middleware(['auth', 'auth.session', RequireTwoFactorAuthentication::class])->group(function () {
    Route::get('/authorize', [OAuthController::class, 'authorizeForm'])->name('authorize');
    Route::post('/authorize', [OAuthController::class, 'authorizeDecision'])->name('decision');
    Route::get('/connections', [ConnectionsController::class, 'index'])->name('connections');
    Route::post('/connections/{grant}/remove', [ConnectionsController::class, 'destroy'])
        ->whereNumber('grant')
        ->name('connections.remove');
});
