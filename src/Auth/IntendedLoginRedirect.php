<?php

namespace PanelMcp\Auth;

use Illuminate\Support\Facades\Session;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Data\LoginCheckpoint;
use Pterodactyl\Data\LoginResult;
use Pterodactyl\Models\User;

/**
 * v2 always answers a completed login with intended "/". When the sign-in started at the
 * OAuth consent page (the auth middleware stored url.intended), send the user back there.
 * Only the local path /oauth/authorize with a query string is honoured: never an absolute
 * URL, so this can't become an open redirect.
 */
final readonly class IntendedLoginRedirect implements CompletesLogins
{
    public function __construct(private CompletesLogins $inner) {}

    public function complete(User $user): LoginResult
    {
        $result = $this->inner->complete($user);

        return $result->complete ? $this->redirected($result) : $result;
    }

    public function establish(User $user): LoginResult
    {
        return $this->redirected($this->inner->establish($user));
    }

    public function pendingCheckpoint(): ?LoginCheckpoint
    {
        return $this->inner->pendingCheckpoint();
    }

    private function redirected(LoginResult $result): LoginResult
    {
        $intended = Session::get('url.intended');
        if (! is_string($intended) || $intended === '') {
            return $result;
        }

        $path = self::localAuthorizePath($intended);
        if ($path === null) {
            return $result;
        }

        Session::forget('url.intended');

        return LoginResult::established($result->user, $path);
    }

    /** "/oauth/authorize?..." for a same-origin URL or path, otherwise null. */
    public static function localAuthorizePath(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['path'] ?? '') !== '/oauth/authorize' || ($parts['query'] ?? '') === '') {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $app = parse_url((string) config('app.url'));
            if (($parts['scheme'] ?? null) !== ($app['scheme'] ?? null)
                || strtolower($parts['host'] ?? '') !== strtolower($app['host'] ?? '')
                || ($parts['port'] ?? null) !== ($app['port'] ?? null)
                || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }
        }

        return '/oauth/authorize?'.$parts['query'];
    }
}
