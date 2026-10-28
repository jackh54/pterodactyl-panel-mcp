<?php

namespace PanelMcp;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pterodactyl\Models\User;

/**
 * The OAuth 2.1 authorization server behind the MCP endpoint.
 *
 * Security model:
 * - Clients are public (no secret) and register themselves, so nothing about a client is trusted:
 *   PKCE (S256) is mandatory, redirect URIs must match exactly, and the user approves every
 *   connection on a consent page that shows where the app will be sent back to.
 * - A user can only approve from a normal panel session (password + 2FA, or a passkey).
 * - Scopes are picked by the user on the consent page and can only narrow what their panel
 *   account may already do. Every tool call still goes through the panel's permission checks.
 * - Codes, access tokens and refresh tokens are random, stored only as SHA-256 hashes, and
 *   bound to one grant. Codes are single use and live for two minutes.
 * - Refresh tokens rotate. Presenting an already-rotated refresh token (after a short grace for
 *   retries) or an already-used code revokes the whole grant.
 * - Revoking a grant (Connected apps) kills its tokens immediately.
 *
 * Table names come from Tables (one configurable prefix).
 */
class OAuthService
{
    public const SCOPE_READ = 'panel:read';

    public const SCOPE_CONTROL = 'panel:control';

    public const SCOPE_WRITE = 'panel:write';

    // Added in 1.1. Its own scope so connections approved earlier do not gain these tools without
    // the user agreeing to them.
    public const SCOPE_MANAGE = 'panel:manage';

    public const SCOPES = [
        self::SCOPE_READ => [
            'title' => 'Read',
            'detail' => 'See your servers, their status and resource use, read the console, files, backups, ports, databases and activity. Root administrators also get the operations guide and, when configured, read-only database queries.',
        ],
        self::SCOPE_CONTROL => [
            'title' => 'Control',
            'detail' => 'Start, stop, restart and kill servers, send console commands, run schedules and take backups.',
        ],
        self::SCOPE_WRITE => [
            'title' => 'Change files and settings',
            'detail' => 'Create, edit, rename, delete, archive and download-to-server files. Change startup settings, the Docker image and schedules.',
        ],
        self::SCOPE_MANAGE => [
            'title' => 'Manage backups, ports and databases',
            'detail' => 'Restore, lock and delete backups, add and remove ports, create and delete databases and see their passwords, rename servers and reinstall them.',
        ],
    ];

    public const CODE_TTL = 120;

    public const ACCESS_TTL = 3600;

    public const REFRESH_TTL = 60 * 60 * 24 * 30;

    public const REFRESH_REUSE_GRACE = 60;

    public const MAX_GRANTS_PER_USER = 20;

    private const TOKEN_PREFIX = 'lmcp_';

    private const BLOCKED_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss'];

    public function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function resource(): string
    {
        return $this->issuer().'/mcp';
    }

    public function resourceMetadataUrl(): string
    {
        return $this->issuer().'/.well-known/oauth-protected-resource/mcp';
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function randomToken(): string
    {
        return self::TOKEN_PREFIX.Str::random(48);
    }

    /**
     * Returns the known scopes out of a space separated list, always including read.
     *
     * @return string[]
     */
    public function parseScopes(?string $scope): array
    {
        $requested = preg_split('/\s+/', trim((string) $scope), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $known = array_values(array_intersect(array_keys(self::SCOPES), $requested));
        if (! in_array(self::SCOPE_READ, $known, true)) {
            array_unshift($known, self::SCOPE_READ);
        }

        return $known;
    }

    /**
     * A redirect URI is acceptable when it is https, http on a loopback host (desktop apps),
     * or an app-specific scheme. Fragments are never allowed.
     */
    public function isAllowedRedirectUri(string $uri): bool
    {
        // A backslash is a path separator to browsers but part of the host to parse_url, which
        // would let the consent page show a host the browser never goes to.
        if (strlen($uri) > 2000 || preg_match('/[\x00-\x20\x7f]/', $uri) || str_contains($uri, '#') || str_contains($uri, '\\')) {
            return false;
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($scheme === 'https') {
            // A plain ASCII host name (IDN hosts must be given in punycode), so the consent page
            // shows exactly where the browser will go.
            return ! empty($parts['host']) && filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        }

        if ($scheme === 'http') {
            return in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
        }

        return ! in_array($scheme, self::BLOCKED_SCHEMES, true);
    }

    public function findClient(mixed $clientId): ?object
    {
        if (! is_string($clientId) || $clientId === '' || strlen($clientId) > 64) {
            return null;
        }

        $client = DB::table(Tables::clients())->where('client_id', $clientId)->first();
        if ($client) {
            $uris = json_decode((string) $client->redirect_uris, true);
            $client->redirect_uris = is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
        }

        return $client;
    }

    /**
     * @param  string[]  $redirectUris
     */
    public function registerClient(string $name, array $redirectUris, ?string $ip): object
    {
        // Registration is open, so forget clients nobody ever connected with.
        DB::table(Tables::clients())
            ->where('created_at', '<', CarbonImmutable::now()->subDays(2))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from(Tables::grants())
                ->whereColumn(Tables::grants().'.client_id', Tables::clients().'.id'))
            ->delete();

        $clientId = 'lmcp-client-'.Str::lower(Str::random(32));
        DB::table(Tables::clients())->insert([
            'client_id' => $clientId,
            'name' => $name,
            'redirect_uris' => json_encode(array_values($redirectUris)),
            'created_ip' => $ip,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->findClient($clientId);
    }

    /**
     * Records the user's approval and returns a single-use authorization code.
     *
     * @param  string[]  $scopes
     */
    public function approve(User $user, object $client, array $scopes, string $redirectUri, string $codeChallenge, ?string $ip): string
    {
        return DB::transaction(function () use ($user, $client, $scopes, $redirectUri, $codeChallenge, $ip) {
            // Keep the list bounded: the oldest connections beyond the cap are revoked.
            $keep = DB::table(Tables::grants())->where('user_id', $user->id)->whereNull('revoked_at')
                ->orderByDesc('id')->limit(self::MAX_GRANTS_PER_USER - 1)->pluck('id');
            $this->revokeWhere(fn ($q) => $q->where('user_id', $user->id)->whereNotIn('id', $keep));

            $grantId = DB::table(Tables::grants())->insertGetId([
                'user_id' => $user->id,
                'client_id' => $client->id,
                'scopes' => implode(' ', $scopes),
                'created_ip' => $ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $code = self::randomToken();
            DB::table(Tables::codes())->insert([
                'code_hash' => self::hash($code),
                'grant_id' => $grantId,
                'redirect_uri' => $redirectUri,
                'code_challenge' => $codeChallenge,
                'expires_at' => CarbonImmutable::now()->addSeconds(self::CODE_TTL),
                'created_at' => now(),
            ]);

            return $code;
        });
    }

    /**
     * Exchanges an authorization code for tokens. Returns null for anything invalid.
     */
    public function exchangeCode(string $code, string $clientId, string $redirectUri, string $verifier): ?array
    {
        if ($code === '') {
            return null;
        }

        return DB::transaction(function () use ($code, $clientId, $redirectUri, $verifier) {
            $row = DB::table(Tables::codes())->where('code_hash', self::hash($code))->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            if ($row->used_at !== null) {
                // A code presented twice means it leaked; drop whatever it produced.
                $this->revokeWhere(fn ($q) => $q->where('id', $row->grant_id));

                return null;
            }

            // Burnt by the first attempt, right or wrong.
            DB::table(Tables::codes())->where('id', $row->id)->update(['used_at' => now()]);

            $grant = $this->activeGrant((int) $row->grant_id);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (
                ! $grant
                || CarbonImmutable::parse($row->expires_at)->isPast()
                || ! hash_equals((string) $grant->client_public_id, $clientId)
                || ! hash_equals((string) $row->redirect_uri, $redirectUri)
                || ! hash_equals((string) $row->code_challenge, $challenge)
            ) {
                return null;
            }

            return $this->issueTokens($grant);
        });
    }

    /**
     * Exchanges a refresh token for a new pair (rotation). Returns null for anything invalid.
     */
    public function refresh(string $refreshToken, string $clientId): ?array
    {
        if ($refreshToken === '') {
            return null;
        }

        return DB::transaction(function () use ($refreshToken, $clientId) {
            $row = DB::table(Tables::tokens())->where('refresh_hash', self::hash($refreshToken))->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            $grant = $this->activeGrant((int) $row->grant_id);
            if (! $grant || ! hash_equals((string) $grant->client_public_id, $clientId)) {
                return null;
            }

            if ($row->rotated_at !== null) {
                if (CarbonImmutable::parse($row->rotated_at)->addSeconds(self::REFRESH_REUSE_GRACE)->isPast()) {
                    $this->revokeWhere(fn ($q) => $q->where('id', $grant->id));
                }

                return null;
            }

            if (CarbonImmutable::parse($row->refresh_expires_at)->isPast()) {
                return null;
            }

            DB::table(Tables::tokens())->where('id', $row->id)->update([
                'rotated_at' => now(),
                // The old access token stops working as soon as its replacement exists.
                'access_expires_at' => now(),
            ]);

            return $this->issueTokens($grant);
        });
    }

    /**
     * Resolves a bearer token to its grant (with ->user_id, ->scopes[], ->client_name) or null.
     */
    public function authenticate(string $accessToken): ?object
    {
        if (! str_starts_with($accessToken, self::TOKEN_PREFIX) || strlen($accessToken) > 128) {
            return null;
        }

        $row = DB::table(Tables::tokens())->where('access_hash', self::hash($accessToken))->first();
        if (! $row || CarbonImmutable::parse($row->access_expires_at)->isPast()) {
            return null;
        }

        return $this->activeGrant((int) $row->grant_id);
    }

    public function touch(object $grant, ?string $ip): void
    {
        // At most one write a minute per connection.
        if ($grant->last_used_at === null || CarbonImmutable::parse($grant->last_used_at)->addMinute()->isPast()) {
            DB::table(Tables::grants())->where('id', $grant->id)->update(['last_used_at' => now(), 'last_used_ip' => $ip]);
            DB::table(Tables::clients())->where('id', $grant->client_id)->update(['last_used_at' => now()]);
        }
    }

    /**
     * Revokes the grant a token (access or refresh) belongs to. Used by the revocation endpoint.
     */
    public function revokeByToken(string $token): void
    {
        $hash = self::hash($token);
        $row = DB::table(Tables::tokens())->where('access_hash', $hash)->orWhere('refresh_hash', $hash)->first();
        if ($row) {
            $this->revokeWhere(fn ($q) => $q->where('id', $row->grant_id));
        }
    }

    public function revokeGrant(User $user, int $grantId): bool
    {
        return $this->revokeWhere(fn ($q) => $q->where('id', $grantId)->where('user_id', $user->id)) > 0;
    }

    /**
     * Active connections of a user, newest first.
     */
    public function grantsFor(User $user): Collection
    {
        return DB::table(Tables::grants().' as g')
            ->join(Tables::clients().' as c', 'c.id', '=', 'g.client_id')
            ->where('g.user_id', $user->id)
            ->whereNull('g.revoked_at')
            // A grant only becomes a connection once its code was exchanged for tokens.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from(Tables::tokens())->whereColumn('grant_id', 'g.id'))
            ->orderByDesc('g.id')
            ->get(['g.id', 'c.name', 'g.scopes', 'g.created_at', 'g.last_used_at', 'g.last_used_ip']);
    }

    /**
     * Housekeeping, called now and then from the token endpoint.
     */
    public function prune(): void
    {
        $now = CarbonImmutable::now();
        DB::table(Tables::codes())->where('expires_at', '<', $now->subHour())->delete();
        DB::table(Tables::tokens())->where('refresh_expires_at', '<', $now)->delete();
        DB::table(Tables::tokens())->whereNotNull('rotated_at')->where('rotated_at', '<', $now->subDay())->delete();
        DB::table(Tables::grants())->whereNotNull('revoked_at')->where('revoked_at', '<', $now->subDays(30))->delete();
        // Approved but never completed.
        DB::table(Tables::grants())->where('created_at', '<', $now->subHour())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from(Tables::tokens())
                ->whereColumn(Tables::tokens().'.grant_id', Tables::grants().'.id'))
            ->delete();
    }

    private function issueTokens(object $grant): array
    {
        $access = self::randomToken();
        $refresh = self::randomToken();
        $now = CarbonImmutable::now();

        DB::table(Tables::tokens())->insert([
            'grant_id' => $grant->id,
            'access_hash' => self::hash($access),
            'refresh_hash' => self::hash($refresh),
            'access_expires_at' => $now->addSeconds(self::ACCESS_TTL),
            'refresh_expires_at' => $now->addSeconds(self::REFRESH_TTL),
            'created_at' => $now,
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => implode(' ', $grant->scopes),
            'grant' => $grant,
        ];
    }

    private function activeGrant(int $grantId): ?object
    {
        $grant = DB::table(Tables::grants().' as g')
            ->join(Tables::clients().' as c', 'c.id', '=', 'g.client_id')
            ->where('g.id', $grantId)
            ->whereNull('g.revoked_at')
            ->first(['g.id', 'g.user_id', 'g.client_id', 'g.scopes', 'g.last_used_at', 'c.name as client_name', 'c.client_id as client_public_id']);

        if ($grant) {
            $grant->scopes = explode(' ', (string) $grant->scopes);
        }

        return $grant;
    }

    /**
     * @param  Closure(\Illuminate\Database\Query\Builder): mixed  $where
     */
    private function revokeWhere(Closure $where): int
    {
        $ids = DB::table(Tables::grants())->whereNull('revoked_at')->where($where)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        DB::table(Tables::tokens())->whereIn('grant_id', $ids)->delete();
        DB::table(Tables::codes())->whereIn('grant_id', $ids)->delete();

        return DB::table(Tables::grants())->whereIn('id', $ids)->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
