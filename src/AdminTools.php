<?php

namespace PanelMcp;

use Pterodactyl\Models\User;
use Pterodactyl\Services\Activity\ActivityLogService;

/**
 * MCP tools that look at the whole panel instead of one server. Root admins only.
 *
 * - The operations guide is a Markdown file on disk, served read-only (get_ops_guide).
 * - Database queries run on a separate MariaDB/MySQL account that should only have SELECT on the
 *   databases offered. That account is the real control; the statement checks here are a second
 *   layer. Point it at game or plugin databases, never at the panel's own database.
 */
class AdminTools
{
    private const MAX_OUTPUT = 100000;

    private const SQL_TIMEOUT_SECONDS = 15;

    private const SQL_ALLOWED = ['select', 'show', 'describe', 'desc', 'explain', 'with'];

    public function guidePath(): string
    {
        return (string) config('panel-mcp.ops_guide_path', storage_path('app/panel-mcp/ops-guide.md'));
    }

    /**
     * @return string[] database names the read-only account may be pointed at
     */
    public function databases(): array
    {
        $list = config('panel-mcp.sql.databases', []);
        if (is_string($list)) {
            $list = explode(',', $list);
        }

        return array_values(array_filter(array_map(fn ($d) => trim((string) $d), (array) $list), fn ($d) => $d !== ''));
    }

    public function sqlConfigured(): bool
    {
        return (string) config('panel-mcp.sql.username', '') !== '' && $this->databases() !== [];
    }

    // ---------------------------------------------------------------------------------------

    public function opsGuide(array $args): string
    {
        $path = $this->guidePath();
        if (! is_file($path) || ! is_readable($path)) {
            throw new ToolException('The operations guide has not been uploaded to the panel yet.');
        }

        $text = (string) file_get_contents($path);
        $updated = gmdate('Y-m-d H:i', (int) filemtime($path)).' UTC';

        // Split on level-2 headings. Index 0 is whatever comes before the first one.
        $parts = preg_split('/^(?=## )/m', $text) ?: [$text];
        $sections = [];
        foreach ($parts as $i => $body) {
            $title = $i === 0 && ! str_starts_with($body, '## ') ? 'Introduction' : trim(substr((string) strtok($body, "\n"), 3));
            $sections[] = ['title' => $title, 'body' => rtrim($body)."\n"];
        }

        $search = trim($this->str($args['search'] ?? ''));
        if ($search !== '') {
            $out = '';
            foreach ($sections as $s) {
                $hits = [];
                foreach (explode("\n", $s['body']) as $line) {
                    if (mb_stripos($line, $search) !== false) {
                        $hits[] = '  '.mb_substr(trim($line), 0, 400);
                    }
                }
                if ($hits) {
                    $out .= "## {$s['title']}\n".implode("\n", array_slice($hits, 0, 15))."\n\n";
                }
            }

            return $out === ''
                ? "Nothing in the guide mentions \"{$search}\"."
                : $this->cap("Lines mentioning \"{$search}\" (ask for a section by its number to read it in full):\n\n".$out);
        }

        $want = trim($this->str($args['section'] ?? ''));
        if ($want !== '') {
            $found = array_values(array_filter($sections, function ($s) use ($want) {
                // "4" matches "4. Restarts…", "2b" matches "2b. Backups…"; anything else matches by text.
                return preg_match('/^'.preg_quote($want, '/').'[.\s]/i', $s['title']) === 1
                    || (! preg_match('/^\d+[a-z]?$/i', $want) && mb_stripos($s['title'], $want) !== false);
            }));
            if ($found === []) {
                throw new ToolException("No section \"{$want}\". Call get_ops_guide with no arguments to see the list.");
            }

            return $this->cap(implode("\n", array_column($found, 'body')));
        }

        $out = "Operations guide (last uploaded {$updated}).\n"
            .'Below: the introduction, the ground rules, then every section title. Read the sections that touch what you are about to do '
            ."(get_ops_guide section: \"19\"), or look something up (get_ops_guide search: \"restart\").\n"
            ."Through this connector only the panel tools are available, whatever else the guide mentions.\n\n";
        foreach ($sections as $i => $s) {
            if ($i === 0 || preg_match('/^1[.\s]/', $s['title'])) {
                $out .= $s['body']."\n";
            }
        }
        $out .= "## Sections\n";
        foreach ($sections as $s) {
            $out .= "- {$s['title']} (".max(1, (int) round(strlen($s['body']) / 1000))." KB)\n";
        }

        return $this->cap($out);
    }

    public function query(array $args, User $user, object $grant): string
    {
        if (! $this->sqlConfigured()) {
            throw new ToolException('Database queries are not set up on this panel.');
        }

        $database = $this->str($args['database'] ?? '');
        if (! in_array($database, $this->databases(), true)) {
            throw new ToolException('database must be one of: '.implode(', ', $this->databases()));
        }

        $sql = rtrim(trim($this->str($args['sql'] ?? '')), "; \n\r\t");
        if ($sql === '' || strlen($sql) > 8000) {
            throw new ToolException('sql must be one statement of at most 8000 characters.');
        }
        // Comments are removed only to find the first word; the statement itself is sent as written.
        $bare = ltrim(preg_replace('#/\*.*?\*/|--[^\n]*|\#[^\n]*#s', ' ', $sql) ?? '', " (\n\r\t");
        $verb = strtolower((string) strtok($bare, " \n\r\t("));
        if (! in_array($verb, self::SQL_ALLOWED, true)) {
            throw new ToolException('Only reading is possible here: SELECT, SHOW, DESCRIBE or EXPLAIN.');
        }
        if (str_contains($sql, ';')) {
            throw new ToolException('One statement at a time, without a ";" inside it.');
        }
        // Checked on the statement with comments blanked too ("INTO/**/OUTFILE"). The account has
        // no FILE privilege either; this is the second layer.
        if (preg_match('/\binto\s+(outfile|dumpfile)\b/i', $sql) || preg_match('/\binto\s+(outfile|dumpfile)\b/i', $bare)) {
            throw new ToolException('Writing query results to a file is not allowed.');
        }

        $limit = max(1, min(500, (int) ($args['max_rows'] ?? 100)));
        $rows = [];
        $more = false;

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) config('panel-mcp.sql.host', '127.0.0.1'), (int) config('panel-mcp.sql.port', 3306), $database),
                (string) config('panel-mcp.sql.username'),
                (string) config('panel-mcp.sql.password', ''),
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 5,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    self::mysqlAttribute('USE_BUFFERED_QUERY') => false,
                    self::mysqlAttribute('MULTI_STATEMENTS') => false,
                ]
            );
            $pdo->exec('SET SESSION max_statement_time = '.self::SQL_TIMEOUT_SECONDS);
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');

            $started = microtime(true);
            $statement = $pdo->query($sql);
            while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if (count($rows) >= $limit) {
                    $more = true;
                    break;
                }
                $rows[] = array_map(function ($v) {
                    if (! is_string($v)) {
                        return $v;
                    }
                    if (! mb_check_encoding($v, 'UTF-8')) {
                        return '0x'.substr(bin2hex($v), 0, 64).(strlen($v) > 32 ? '…' : '');
                    }

                    return mb_strlen($v) > 500 ? mb_substr($v, 0, 500).'…' : $v;
                }, $row);
            }
            $statement->closeCursor();
            $ms = (int) round((microtime(true) - $started) * 1000);
        } catch (\PDOException $e) {
            // The driver message names the problem (bad column, no permission, timeout) and holds no secrets.
            throw new ToolException('The database refused that: '.preg_replace('/^SQLSTATE\[\w+\](?: \[\d+\])?:? ?/', '', $e->getMessage()));
        } finally {
            $statement = null;
            $pdo = null;
        }

        app(ActivityLogService::class)
            ->event('user:mcp.query')
            ->actor($user)
            ->subject($user)
            ->property(['database' => $database, 'sql' => mb_substr($sql, 0, 1000), 'rows' => count($rows)])
            ->log();

        $out = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $note = count($rows).' row'.(count($rows) === 1 ? '' : 's')." in {$ms} ms"
            .($more ? ". There are more: only the first {$limit} are shown, so narrow the query or add LIMIT/ORDER BY" : '').'.';

        return $this->cap($note."\n".$out);
    }

    /**
     * Catalog entries in the same shape McpTools uses, with a "run" closure instead of a method name.
     */
    public function catalog(): array
    {
        $read = OAuthService::SCOPE_READ;
        $tools = [
            'get_ops_guide' => [
                'scope' => $read, 'admin' => true, 'title' => 'Operations guide',
                'run' => fn (array $a) => $this->opsGuide($a),
                'description' => 'The operations guide written by the administrators of this panel: ground rules, how the servers fit together, safe procedures (restarts, maintenance) and warnings about specific plugins and commands. Call it with no arguments at the start of any session that may change something, then read the sections that apply before acting.',
                'properties' => [
                    'section' => ['type' => 'string', 'description' => 'Section number (e.g. "19", "12c") or part of its title, to read it in full.'],
                    'search' => ['type' => 'string', 'description' => 'Show every line that mentions this text, grouped by section.'],
                ],
                'required' => [],
            ],
        ];

        if ($this->sqlConfigured()) {
            $names = implode(', ', $this->databases());
            $tools['query_database'] = [
                'scope' => $read, 'admin' => true, 'title' => 'Query a game database',
                'run' => fn (array $a, User $u, object $g) => $this->query($a, $u, $g),
                'description' => "Runs one read-only SQL statement (SELECT, SHOW, DESCRIBE, EXPLAIN) on a game database: {$names}. MariaDB syntax. Use SHOW TABLES and DESCRIBE to learn the layout first. Stops after ".self::SQL_TIMEOUT_SECONDS.' seconds, so filter on indexed columns and always add a LIMIT on big tables. Results can include player IP addresses: do not repeat those unless the user asked for them.',
                'properties' => [
                    'database' => ['type' => 'string', 'enum' => $this->databases()],
                    'sql' => ['type' => 'string', 'description' => 'One statement, no trailing semicolon needed.'],
                    'max_rows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Rows to return (default 100).'],
                ],
                'required' => ['database', 'sql'],
            ];
        }

        return $tools;
    }

    /**
     * PDO's MySQL attribute ids moved to Pdo\Mysql in PHP 8.4 (the old names are deprecated in 8.5).
     */
    private static function mysqlAttribute(string $name): int
    {
        $current = 'Pdo\\Mysql::ATTR_'.$name;

        return (int) constant(defined($current) ? $current : 'PDO::MYSQL_ATTR_'.$name);
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function cap(string $text): string
    {
        return strlen($text) <= self::MAX_OUTPUT
            ? $text
            : mb_strcut($text, 0, self::MAX_OUTPUT)."\n\n[cut short: ask for a narrower section, search or query]";
    }
}
