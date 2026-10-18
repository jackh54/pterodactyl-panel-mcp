<?php

// Merged as config('panel-mcp.*') by PanelMcpProvider::register(). env() is read only here, so a
// cached config still carries the values.
return [
    // Prefix of the four OAuth tables. Leave it alone unless you are pointing the extension at tables
    // an earlier install already created.
    'table_prefix' => env('PANEL_MCP_TABLE_PREFIX', 'ext_panel_mcp_'),

    // Markdown file served to root admins by the get_ops_guide tool. Missing file = tool says so.
    // `php artisan panel-mcp:guide <file>` copies a file here.
    'ops_guide_path' => env('PANEL_MCP_OPS_GUIDE_PATH', storage_path('app/panel-mcp/ops-guide.md')),

    // Optional read-only SQL for the query_database tool (root admins only). Give it a database account
    // that can only SELECT. Leave the username empty and the tool does not exist.
    'sql' => [
        'host' => env('PANEL_MCP_SQL_HOST', '127.0.0.1'),
        'port' => (int) env('PANEL_MCP_SQL_PORT', 3306),
        'username' => env('PANEL_MCP_SQL_USERNAME'),
        'password' => env('PANEL_MCP_SQL_PASSWORD'),
        'databases' => array_values(array_filter(array_map('trim', explode(',', (string) env('PANEL_MCP_SQL_DATABASES', ''))))),
    ],

    // Icon shown on the consent and Connected Apps pages (a URL or path). Empty = the panel's favicon.
    'brand_icon' => env('PANEL_MCP_BRAND_ICON', ''),

    // Requests per minute per connection.
    'requests_per_minute' => (int) env('PANEL_MCP_REQUESTS_PER_MINUTE', 120),
];
