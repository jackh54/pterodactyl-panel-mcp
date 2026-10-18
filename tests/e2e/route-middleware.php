<?php

// Asserts the middleware Laravel actually runs for the MCP and OAuth routes, so a change in how
// the extension SDK mounts root routes cannot silently give the machine endpoints a session,
// cookies or CSRF, or take the login/CSRF/2FA layers off the browser pages.
// Prints ROUTE_MIDDLEWARE_OK on success.
//   cd <instance> && php artisan tinker --execute="require '<repo>/tests/mcp/route-middleware.php';"

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Pterodactyl\Http\Middleware\EnsureExtensionIsAvailable;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;

$router = app('router');
$problems = [];
$stateful = [StartSession::class, EncryptCookies::class, PreventRequestForgery::class, AuthenticateSession::class];

$gather = function (string $name) use ($router, &$problems): ?array {
    $route = $router->getRoutes()->getByName($name);
    if (! $route) {
        $problems[] = "missing route {$name}";

        return null;
    }

    return array_map(fn ($m) => is_string($m) ? $m : get_class($m), $router->gatherRouteMiddleware($route));
};
$has = fn (array $middleware, string $class) => (bool) array_filter($middleware, fn ($m) => $m === $class || str_starts_with($m, $class.':'));

$machine = [
    'extensions.panel-mcp.root.mcp.handle' => null,
    'extensions.panel-mcp.root.mcp.stream' => null,
    'extensions.panel-mcp.root.oauth.register' => 'throttle:20,1,panel-mcp-oauth-register',
    'extensions.panel-mcp.root.oauth.token' => 'throttle:60,1,panel-mcp-oauth-token',
    'extensions.panel-mcp.root.oauth.revoke' => 'throttle:60,1,panel-mcp-oauth-revoke',
];
foreach ($machine as $name => $throttle) {
    $middleware = $gather($name);
    if ($middleware === null) {
        continue;
    }
    foreach ($stateful as $class) {
        if ($has($middleware, $class)) {
            $problems[] = "{$name} runs {$class}";
        }
    }
    foreach ($middleware as $m) {
        if (str_contains($m, 'ThrottleRequests') && str_contains($m, 'extensions.root')) {
            $problems[] = "{$name} runs the root-route throttle";
        }
    }
    if (! $has($middleware, EnsureExtensionIsAvailable::class)) {
        $problems[] = "{$name} lost EnsureExtensionIsAvailable";
    }
    $throttles = array_values(array_filter($middleware, fn ($m) => str_contains($m, 'ThrottleRequests')));
    $expected = $throttle === null ? [] : [str_replace('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class, $throttle)];
    if ($throttles !== $expected) {
        $problems[] = "{$name} throttles: ".json_encode($throttles);
    }
}

foreach (['authorize', 'decision', 'connections', 'connections.remove'] as $page) {
    $middleware = $gather("extensions.panel-mcp.root.oauth.{$page}");
    if ($middleware === null) {
        continue;
    }
    foreach ([StartSession::class, EncryptCookies::class, PreventRequestForgery::class, Authenticate::class, AuthenticateSession::class, RequireTwoFactorAuthentication::class, EnsureExtensionIsAvailable::class] as $class) {
        if (! $has($middleware, $class)) {
            $problems[] = "oauth.{$page} is missing {$class}";
        }
    }
}

echo $problems === [] ? "ROUTE_MIDDLEWARE_OK\n" : 'ROUTE_MIDDLEWARE_FAILED: '.implode('; ', $problems)."\n";
