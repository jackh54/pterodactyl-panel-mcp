<?php

namespace PanelMcpFixture;

use Pterodactyl\Extensions\ExtensionProvider;

class FixtureProvider extends ExtensionProvider
{
    public function register(): void
    {
        // The tag name is a plain string so this works without loading any Panel MCP class.
        $this->app->tag([FixtureTools::class], 'panel-mcp.tools');
    }
}
