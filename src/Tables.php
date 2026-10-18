<?php

namespace PanelMcp;

/**
 * Table names. Every table shares one prefix (default `ext_panel_mcp_`), set with
 * PANEL_MCP_TABLE_PREFIX. Change it only before the first install, or to point the extension at
 * tables an earlier install already filled.
 */
final class Tables
{
    public static function prefix(): string
    {
        $prefix = (string) config('panel-mcp.table_prefix', 'ext_panel_mcp_');

        return preg_match('/^[a-z][a-z0-9_]{0,30}$/', $prefix) === 1 ? $prefix : 'ext_panel_mcp_';
    }

    public static function clients(): string
    {
        return self::prefix().'oauth_clients';
    }

    public static function grants(): string
    {
        return self::prefix().'oauth_grants';
    }

    public static function codes(): string
    {
        return self::prefix().'oauth_codes';
    }

    public static function tokens(): string
    {
        return self::prefix().'oauth_tokens';
    }
}
