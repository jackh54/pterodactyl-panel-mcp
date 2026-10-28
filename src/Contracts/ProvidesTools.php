<?php

namespace PanelMcp\Contracts;

/**
 * Lets another extension (or the panel's own service providers) add tools to the MCP server.
 *
 * Implement this on a class and tag it in your provider's register() or boot():
 *
 *     $this->app->tag([MyTools::class], \PanelMcp\Contracts\ProvidesTools::TAG);
 *
 * Tagging only stores the class name, so it is safe even when Panel MCP is not installed: the class
 * is only loaded when the MCP server asks for tools.
 *
 * Each tool is an array:
 *
 *     'my_tool' => [
 *         'scope'       => 'panel:read',          // panel:read, panel:control, panel:write or panel:manage
 *         'title'       => 'Short title',
 *         'description' => 'What it does and when to use it.',
 *         'properties'  => ['server' => ['type' => 'string']],   // JSON Schema properties
 *         'required'    => ['server'],
 *         'run'         => fn (array $args, User $user, object $grant) => 'text or an array',
 *         'admin'       => false,                  // true = root admins only, hidden from everyone else
 *         'destructive' => false,                  // shown to clients as destructiveHint
 *     ]
 *
 * Your tool does its own permission checks: the MCP server only checks the scope and the admin flag.
 * Throw \PanelMcp\ToolException with a message to report an error to the client.
 * Built-in tool names win over added ones, except a built-in this panel build cannot offer.
 */
interface ProvidesTools
{
    public const TAG = 'panel-mcp.tools';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array;
}
