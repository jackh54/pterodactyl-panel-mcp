<?php

namespace PanelMcp\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PanelMcp\OAuthService;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;

/**
 * OAuth 2.1 endpoints for the MCP server: client registration, consent, tokens, revocation.
 * Discovery documents are served by WellKnownController.
 *
 * register/token/revoke are machine endpoints (no session, no cookies, no CSRF). The consent
 * page runs in a normal signed-in panel session with CSRF protection.
 *
 * @see OAuthService for the security model.
 */
final class OAuthController
{
    private const PENDING_KEY = 'panel-mcp.oauth.pending';

    private const PENDING_TTL = 600;

    public function __construct(private readonly OAuthService $oauth) {}

    /**
     * RFC 7591 dynamic client registration. Open, so it only ever creates public clients.
     */
    public function register(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $uris = is_array($body) ? ($body['redirect_uris'] ?? null) : null;

        if (! is_array($uris) || ! array_is_list($uris) || count($uris) < 1 || count($uris) > 10) {
            return $this->error('invalid_redirect_uri', 'redirect_uris must be a list of 1 to 10 URIs.');
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || ! $this->oauth->isAllowedRedirectUri($uri)) {
                return $this->error('invalid_redirect_uri', 'Redirect URIs must be https, http on localhost, or an app scheme.');
            }
        }

        $name = is_string($body['client_name'] ?? null) ? $body['client_name'] : '';
        $name = trim(preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '', $name) ?? '');
        $name = $name === '' ? 'Unnamed app' : mb_substr($name, 0, 100);

        $client = $this->oauth->registerClient($name, $uris, $request->ip());

        return new JsonResponse([
            'client_id' => $client->client_id,
            'client_id_issued_at' => time(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], Response::HTTP_CREATED, ['Cache-Control' => 'no-store']);
    }

    /**
     * Shows the consent page to a signed-in user.
     */
    public function authorizeForm(Request $request): Response|RedirectResponse
    {
        $client = $this->oauth->findClient($request->query('client_id'));
        $redirectUri = $request->query('redirect_uri');

        // Never redirect to an address that was not registered: show the problem here instead.
        if (! $client || ! is_string($redirectUri) || ! in_array($redirectUri, $client->redirect_uris, true)) {
            return $this->problem('This app is not registered with the panel, or it asked to be sent back to an address it did not register. Start the connection again from the app.');
        }

        $state = $request->query('state');
        $state = is_string($state) && strlen($state) <= 1024 ? $state : null;

        if ($request->query('response_type') !== 'code') {
            return $this->back($redirectUri, ['error' => 'unsupported_response_type'], $state);
        }

        $challenge = $request->query('code_challenge');
        if ($request->query('code_challenge_method') !== 'S256' || ! is_string($challenge) || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            return $this->back($redirectUri, ['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.'], $state);
        }

        $resource = $request->query('resource');
        if ($resource !== null && (! is_string($resource) || rtrim($resource, '/') !== $this->oauth->resource())) {
            return $this->back($redirectUri, ['error' => 'invalid_target'], $state);
        }

        $rid = Str::random(40);
        $pending = array_filter(
            (array) $request->session()->get(self::PENDING_KEY, []),
            fn ($p) => is_array($p) && ($p['expires'] ?? 0) > time()
        );
        $pending[$rid] = [
            'client_id' => $client->client_id,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $challenge,
            'expires' => time() + self::PENDING_TTL,
        ];
        $request->session()->put(self::PENDING_KEY, array_slice($pending, -5, null, true));

        $target = parse_url($redirectUri) ?: [];

        return response()->view('ext-panel-mcp::oauth.authorize', [
            'rid' => $rid,
            'client' => $client,
            'user' => $request->user(),
            'scopes' => OAuthService::SCOPES,
            'required' => OAuthService::SCOPE_READ,
            'returnsTo' => isset($target['host'])
                ? $target['host'].(isset($target['port']) ? ':'.$target['port'] : '')
                : ($target['scheme'] ?? '').' (an app on this device)',
            'local' => in_array(strtolower($target['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Handles the consent form (CSRF protected by the web middleware group).
     */
    public function authorizeDecision(Request $request): Response|RedirectResponse
    {
        $rid = $this->input($request, 'rid');
        $all = (array) $request->session()->get(self::PENDING_KEY, []);
        $pending = $rid !== '' ? ($all[$rid] ?? null) : null;
        unset($all[$rid]);
        $request->session()->put(self::PENDING_KEY, $all);

        $client = is_array($pending) ? $this->oauth->findClient($pending['client_id'] ?? null) : null;
        if (! $client || ($pending['expires'] ?? 0) < time() || ! in_array($pending['redirect_uri'] ?? null, $client->redirect_uris, true)) {
            return $this->problem('This request has expired. Start the connection again from the app.');
        }

        if ($this->input($request, 'decision') !== 'approve') {
            return $this->back($pending['redirect_uri'], ['error' => 'access_denied'], $pending['state']);
        }

        /** @var User $user */
        $user = $request->user();
        $scopes = $this->oauth->parseScopes(implode(' ', array_filter((array) $request->input('scopes', []), 'is_string')));
        $code = $this->oauth->approve($user, $client, $scopes, $pending['redirect_uri'], $pending['code_challenge'], $request->ip());

        Activity::event('user:mcp.connect')
            ->withRequestMetadata()
            ->subject($user)
            ->property(['app' => $client->name, 'scopes' => $scopes])
            ->log();
        $this->notify($user, $client->name, $scopes);

        return $this->back($pending['redirect_uri'], ['code' => $code], $pending['state']);
    }

    /**
     * Token endpoint: authorization_code (with PKCE) and refresh_token grants.
     */
    public function token(Request $request): JsonResponse
    {
        if (random_int(1, 50) === 1) {
            $this->oauth->prune();
        }

        $clientId = $this->input($request, 'client_id');
        $tokens = match ($request->input('grant_type')) {
            'authorization_code' => $this->oauth->exchangeCode(
                $this->input($request, 'code'),
                $clientId,
                $this->input($request, 'redirect_uri'),
                $this->input($request, 'code_verifier')
            ),
            'refresh_token' => $this->oauth->refresh($this->input($request, 'refresh_token'), $clientId),
            default => false,
        };

        if ($tokens === false) {
            return $this->error('unsupported_grant_type', 'Use authorization_code or refresh_token.');
        }
        if ($tokens === null) {
            return $this->error('invalid_grant', 'The code or refresh token is invalid, expired or already used.');
        }

        unset($tokens['grant']);

        return new JsonResponse($tokens, Response::HTTP_OK, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * RFC 7009 revocation. Always answers 200. Revokes the whole connection.
     */
    public function revoke(Request $request): JsonResponse
    {
        $token = $this->input($request, 'token');
        if ($token !== '') {
            $this->oauth->revokeByToken($token);
        }

        return new JsonResponse([], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    /**
     * A request field as a string; anything else (missing, array, number) is ''.
     */
    private function input(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    /**
     * Sends the browser back to the app. Every response carries iss (RFC 9207), errors included,
     * because the discovery document says it does.
     */
    private function back(string $redirectUri, array $params, ?string $state): RedirectResponse
    {
        $params['iss'] = $this->oauth->issuer();
        if ($state !== null) {
            $params['state'] = $state;
        }

        $url = $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query($params);

        return redirect()->away($url)->header('Cache-Control', 'no-store');
    }

    private function problem(string $message): Response
    {
        return response()->view('ext-panel-mcp::oauth.problem', ['message' => $message], Response::HTTP_BAD_REQUEST)
            ->header('Cache-Control', 'no-store');
    }

    private function error(string $code, string $description): JsonResponse
    {
        return new JsonResponse(
            ['error' => $code, 'error_description' => $description],
            Response::HTTP_BAD_REQUEST,
            ['Cache-Control' => 'no-store']
        );
    }

    /**
     * Security notification to the account owner. Never blocks the request if mail fails.
     *
     * @param  string[]  $scopes
     */
    private function notify(User $user, string $app, array $scopes): void
    {
        $email = $user->email;
        $userId = $user->id;
        // Semicolons: a scope title can contain commas ("Manage backups, ports and databases").
        $access = implode('; ', array_map(fn ($s) => strtolower(OAuthService::SCOPES[$s]['title']), $scopes));
        $url = $this->oauth->issuer().'/oauth/connections';

        $panel = (string) config('app.name', 'Pterodactyl');

        dispatch(function () use ($email, $userId, $app, $access, $url, $panel) {
            try {
                Mail::raw(
                    "The app \"{$app}\" was connected to your {$panel} account with this access: {$access}.\n\n"
                    ."If this was not you, remove it at {$url}, change your password and contact an administrator.",
                    fn ($m) => $m->to($email)->subject("{$panel}: an app was connected to your account")
                );
            } catch (\Throwable $e) {
                Log::warning('Panel MCP connection email failed', ['user' => $userId, 'error' => $e->getMessage()]);
            }
        })->afterResponse();
    }
}
