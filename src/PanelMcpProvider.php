<?php

namespace PanelMcp;

use Illuminate\Support\Facades\Route;
use PanelMcp\Auth\IntendedLoginRedirect;
use PanelMcp\Console\GuideCommand;
use PanelMcp\Http\WellKnownController;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

/**
 * Panel MCP: an MCP server (/mcp) inside the panel, with its own OAuth 2.1 authorization server
 * (/oauth/*, /.well-known/oauth-*) so MCP clients connect with the user's normal panel sign-in.
 */
class PanelMcpProvider extends ExtensionProvider
{
    public const ID = 'panel-mcp';

    public const VERSION = '1.0.0';

    public function register(): void
    {
        $this->mergeConfigFrom($this->extensionPath('resources', 'config', 'panel-mcp.php'), 'panel-mcp');
    }

    public function boot(): void
    {
        $this->loadExtensionMigrations();
        $this->loadExtensionViews();

        // Root prefixes claimed in extension.json.
        $this->registerRootRoutes($this->extensionPath('routes', 'root-oauth.php'), 'oauth');
        $this->registerRootRoutes($this->extensionPath('routes', 'root-mcp.php'), 'mcp');

        $this->registerCommands([GuideCommand::class]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('instructions', 'instructions', '', ['nullable', 'string', 'max:4000'])
                ->label('Extra instructions for AI clients')
                ->help('Added to the instructions every MCP client receives when it connects, e.g. house rules or which servers are safe to restart. Plain text.')
                ->tab('Assistant')
                ->textarea(4000),
        ]));

        // The panel always returns "/" after a login; send sign-ins that started on the consent page back to it.
        $this->wrapAction(CompletesLogins::class, fn (CompletesLogins $inner): CompletesLogins => new IntendedLoginRedirect($inner));

        // Activity log wording for user:mcp.* (merged into the panel's `activity` translation group).
        $loader = $this->app->make('translation.loader');
        if (method_exists($loader, 'addPath')) {
            $loader->addPath($this->extensionPath('src', 'lang'));
        }

        // OAuth discovery lives under /.well-known, which routes.root cannot claim (leading dot).
        Route::middleware(['throttle:120,1'])->group(function (): void {
            Route::get('/.well-known/oauth-protected-resource', [WellKnownController::class, 'protectedResource']);
            Route::get('/.well-known/oauth-protected-resource/mcp', [WellKnownController::class, 'protectedResource']);
            Route::get('/.well-known/oauth-authorization-server', [WellKnownController::class, 'authorizationServer']);
            Route::get('/.well-known/oauth-authorization-server/mcp', [WellKnownController::class, 'authorizationServer']);
        });
    }

    /** One of this extension's settings, or the default when unset or unreadable. */
    public static function setting(string $key, mixed $default = null): mixed
    {
        try {
            return app(ExtensionRepository::class)->settings(self::ID)->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
