<?php

namespace PanelMcp\Http;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PanelMcp\OAuthService;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;

/**
 * The signed-in user's connected apps (MCP connections), with a remove button.
 */
final class ConnectionsController
{
    public function __construct(private readonly OAuthService $oauth) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $connections = $this->oauth->grantsFor($user)->map(fn ($g) => [
            'id' => $g->id,
            'name' => $g->name,
            'access' => array_map(
                fn ($s) => OAuthService::SCOPES[$s]['title'] ?? $s,
                explode(' ', (string) $g->scopes)
            ),
            'created' => Carbon::parse($g->created_at)->toAtomString(),
            'last_used' => $g->last_used_at ? Carbon::parse($g->last_used_at)->toAtomString() : null,
            'last_ip' => $g->last_used_ip,
        ]);

        return response()->view('ext-panel-mcp::oauth.connections', [
            'connections' => $connections,
            'endpoint' => $this->oauth->resource(),
            'removed' => (bool) $request->session()->pull('panel-mcp.oauth.removed'),
        ])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, int $grant): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $row = $this->oauth->grantsFor($user)->firstWhere('id', $grant);
        if ($row && $this->oauth->revokeGrant($user, $grant)) {
            Activity::event('user:mcp.disconnect')
                ->withRequestMetadata()
                ->subject($user)
                ->property('app', $row->name)
                ->log();
            $request->session()->put('panel-mcp.oauth.removed', true);
        }

        return redirect('/oauth/connections');
    }
}
