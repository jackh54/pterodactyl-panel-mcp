<?php

namespace PanelMcp;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PanelMcp\Contracts\ProvidesTools;
use Pterodactyl\Contracts\Allocations\AssignsAllocations;
use Pterodactyl\Contracts\Allocations\SetsPrimaryAllocations;
use Pterodactyl\Contracts\Allocations\UnassignsAllocations;
use Pterodactyl\Contracts\Allocations\UpdatesAllocationNotes;
use Pterodactyl\Contracts\Backups\DeletesBackups;
use Pterodactyl\Contracts\Backups\GeneratesBackupDownloadLinks;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Contracts\Backups\RestoresBackups;
use Pterodactyl\Contracts\Backups\TogglesBackupLocks;
use Pterodactyl\Contracts\Databases\DeletesDatabases;
use Pterodactyl\Contracts\Databases\DeploysServerDatabases;
use Pterodactyl\Contracts\Databases\RotatesDatabasePasswords;
use Pterodactyl\Contracts\Files\CompressesFiles;
use Pterodactyl\Contracts\Files\CopiesFiles;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DecompressesFiles;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\PullsFiles;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Contracts\Schedules\CreatesSchedules;
use Pterodactyl\Contracts\Schedules\CreatesScheduleTasks;
use Pterodactyl\Contracts\Schedules\DeletesSchedules;
use Pterodactyl\Contracts\Schedules\DeletesScheduleTasks;
use Pterodactyl\Contracts\Schedules\ProcessesSchedules;
use Pterodactyl\Contracts\Schedules\UpdatesSchedules;
use Pterodactyl\Contracts\Schedules\UpdatesScheduleTasks;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Contracts\Servers\ReinstallsServers;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Contracts\Servers\UpdatesServerBuild;
use Pterodactyl\Contracts\Servers\UpdatesServerDetails;
use Pterodactyl\Contracts\Servers\UpdatesServerDockerImage;
use Pterodactyl\Contracts\Servers\UpdatesServerStartup;
use Pterodactyl\Enum\JwtScope;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Enum\ResourceLimit;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Exceptions\Http\Server\ServerStateConflictException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Facades\LogTarget;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Task;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Activity\ActivityLogService;
use Pterodactyl\Support\DatabaseName;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The tools offered over MCP.
 *
 * Every tool needs two things: the connection must hold the tool's scope (chosen by the user on
 * the consent page), and the user must hold the same panel permission the matching panel API
 * endpoint requires. Actions are written to the activity log as the user, under the same event
 * names as the panel, so they read exactly like the user's own clicks in the panel.
 *
 * Server work goes through the panel's action contracts (Pterodactyl\Contracts\*), so anything
 * that wraps those actions sees MCP calls too. Most of those actions log activity themselves;
 * see log() for how this class avoids a second entry.
 *
 * The backup, port, database and settings tools resolve their action when they run instead of
 * taking it in the constructor, and a tool whose action this panel build lacks is not listed
 * ("requires" in catalog()). An older 2.0 build then loses those tools, not the whole server.
 */
class McpTools
{
    private const MAX_OUTPUT = 120000;

    private const MAX_READ_BYTES = 16 * 1024 * 1024;

    private const SEARCH_SECONDS = 20;

    private const SEARCH_MAX_FOLDERS = 300;

    private const SEARCH_MAX_FOLDERS_BY_NAME = 1500;

    private const UPLOAD_MAX_BYTES = 4 * 1024 * 1024;

    private const BASE64_MAX_BYTES = 256 * 1024;

    private const ARCHIVE_MAX_BYTES = 64 * 1024 * 1024;

    private const ARCHIVE_ENTRY_MAX_BYTES = 4 * 1024 * 1024;

    // Reverse proxies such as Cloudflare close requests after 100 seconds; stay under that.
    private const WAIT_MAX_SECONDS = 90;

    private const SEARCH_MAX_FILES = 250;

    private const SEARCH_MAX_FILE_BYTES = 2 * 1024 * 1024;

    private const SEARCH_TEXT_TYPES = ['yml', 'yaml', 'json', 'properties', 'toml', 'conf', 'cfg', 'ini', 'txt', 'sk', 'md', 'xml', 'csv', 'lang', 'env', 'sh', 'js', 'mcmeta'];

    private const SEARCH_SKIP_FOLDERS = ['region', 'entities', 'poi', 'playerdata', 'stats', 'advancements', 'cache', 'libraries', 'versions', 'logs', 'crash-reports', '.git', 'node_modules', '.paper-remapped', 'dumps'];

    /** Wings never returns more console lines than this. */
    private const CONSOLE_MAX_LINES = ReadsServerLogs::MAX_LINES;

    private User $user;

    private object $grant;

    /** @var list<ActivityLog> entries the panel wrote during the current tool call */
    private array $logged = [];

    private bool $listening = false;

    /** @var array<string, array>|null tools added by other extensions, loaded once */
    private ?array $added = null;

    public function __construct(
        private readonly ListsDirectories $directories,
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly CreatesDirectories $folders,
        private readonly RenamesFiles $renamer,
        private readonly CopiesFiles $copier,
        private readonly DeletesFiles $deleter,
        private readonly CompressesFiles $compressor,
        private readonly DecompressesFiles $decompressor,
        private readonly PullsFiles $puller,
        private readonly ReadsServerLogs $logs,
        private readonly SendsServerPower $power,
        private readonly SendsServerCommands $commands,
        private readonly ProcessesSchedules $processor,
        private readonly CreatesSchedules $scheduleCreator,
        private readonly UpdatesSchedules $scheduleUpdater,
        private readonly DeletesSchedules $scheduleDeleter,
        private readonly CreatesScheduleTasks $taskCreator,
        private readonly UpdatesScheduleTasks $taskUpdater,
        private readonly DeletesScheduleTasks $taskDeleter,
        private readonly UpdatesServerBuild $build,
        private readonly UpdatesServerStartup $startup,
        private readonly AdminTools $admin,
        private readonly Wings $wings,
    ) {}

    /**
     * Tool definitions the given scopes may use, in MCP "tools/list" form.
     *
     * @param  string[]  $scopes
     */
    public function definitions(array $scopes, User $user): array
    {
        $out = [];
        foreach ($this->catalog() as $name => $tool) {
            if (! in_array($tool['scope'], $scopes, true) || (! empty($tool['admin']) && ! $user->root_admin)) {
                continue;
            }

            $readOnly = $tool['scope'] === OAuthService::SCOPE_READ;
            $out[] = [
                'name' => $name,
                'title' => $tool['title'],
                'description' => $tool['description'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) $tool['properties'],
                    'required' => $tool['required'],
                    'additionalProperties' => false,
                ],
                'annotations' => [
                    'title' => $tool['title'],
                    'readOnlyHint' => $readOnly,
                    'destructiveHint' => $tool['destructive'] ?? false,
                    'openWorldHint' => false,
                ],
            ];
        }

        return $out;
    }

    /**
     * Runs a tool and returns its text output.
     *
     * @throws ToolException for anything the client should be told about
     */
    public function call(string $name, array $args, User $user, object $grant): string
    {
        $tool = $this->catalog()[$name] ?? null;
        // Admin-only tools do not exist as far as other accounts are concerned.
        if (! $tool || (! empty($tool['admin']) && ! $user->root_admin)) {
            throw new ToolException("Unknown tool: {$name}");
        }

        if (! in_array($tool['scope'], $grant->scopes, true)) {
            $title = OAuthService::SCOPES[$tool['scope']]['title'];
            throw new ToolException("This connection was not given \"{$title}\" access. The account owner can remove it under Connected apps in the panel and connect again with that box ticked.");
        }

        foreach ($tool['required'] as $key) {
            if (! array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
                throw new ToolException("Missing required argument: {$key}");
            }
        }

        $this->user = $user;
        $this->grant = $grant;

        // The panel's actions log their own activity, with the actor taken from LogTarget (set by
        // the panel's API middleware, which MCP requests do not pass through). Without this they
        // are written as "system".
        LogTarget::setActor($user);
        if (! $this->listening) {
            Event::listen(ActivityLogged::class, function (ActivityLogged $e): void {
                $this->logged[] = $e->model;
            });
            $this->listening = true;
        }
        $this->logged = [];

        try {
            $result = isset($tool['run']) ? $tool['run']($args, $user, $grant) : $this->{$tool['handler']}($args);
        } catch (ToolException $e) {
            throw $e;
        } catch (FileSizeTooLargeException) {
            throw new ToolException('That file is too large to open this way.');
        } catch (ValidationException $e) {
            throw new ToolException('Not accepted: '.implode(' ', Arr::flatten($e->errors())));
        } catch (DaemonConnectionException|DisplayException $e) {
            throw new ToolException($e->getMessage());
        } catch (HttpExceptionInterface $e) {
            // Panel actions refuse with HTTP exceptions whose message is meant for the user
            // (e.g. "Server must be online in order to send commands.").
            throw new ToolException($e->getMessage() !== '' ? $e->getMessage() : 'The panel refused that request.');
        }

        return is_string($result)
            ? $result
            : (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    // ---------------------------------------------------------------------------------------
    // Read
    // ---------------------------------------------------------------------------------------

    private function listServers(array $args): array
    {
        $query = $this->user->root_admin
            ? Server::query()
            : Server::query()->whereIn('servers.id', $this->user->accessibleServers()->pluck('servers.id')->all());

        // node and transfer are read by the state check (Server::validateCurrentState()).
        $servers = $query->with(['allocation', 'node', 'transfer'])->orderBy('name')->get();

        $filter = mb_strtolower(trim($this->text($args, 'filter')));
        if ($filter !== '') {
            $servers = $servers->filter(fn (Server $s) => str_contains(mb_strtolower($s->name.' '.$s->description), $filter));
        }

        $live = [];
        foreach ($servers->pluck('node')->filter()->unique('id') as $node) {
            try {
                $live += Cache::remember("panel-mcp:node-states:{$node->id}", 10, fn () => $this->wings->nodeStates($node));
            } catch (DaemonConnectionException) {
                // The list is still useful without live state.
            }
        }

        return [
            'count' => $servers->count(),
            'servers' => $servers->map(function (Server $s) use ($live) {
                $u = $live[$s->uuid]['utilization'] ?? null;

                return array_filter([
                    'id' => $s->uuidShort,
                    'name' => $s->name,
                    'description' => $s->description ?: null,
                    'state' => $this->blockedReason($s) ?? ($live[$s->uuid]['state'] ?? 'unknown'),
                    'address' => $s->allocation ? $this->address($s->allocation) : null,
                    'cpu_percent' => $u ? round((float) Arr::get($u, 'cpu_absolute', 0), 1) : null,
                    'memory_mb' => $u ? (int) round(Arr::get($u, 'memory_bytes', 0) / 1048576) : null,
                    'memory_limit_mb' => $s->memory ?: 'unlimited',
                ], fn ($v) => $v !== null);
            })->values()->all(),
        ];
    }

    private function getServer(array $args): array
    {
        $server = $this->server($args['server'], null);
        $server->loadMissing(['allocation', 'allocations', 'node', 'egg']);

        $stats = null;
        if (! $this->blockedReason($server)) {
            $stats = $this->details($server);
        }

        $uptime = (int) Arr::get($stats ?? [], 'utilization.uptime', 0);
        $out = [
            'id' => $server->uuidShort,
            'uuid' => $server->uuid,
            'name' => $server->name,
            'description' => $server->description ?: null,
            'state' => $this->blockedReason($server) ?? Arr::get($stats ?? [], 'state', 'unknown'),
            'usage' => $stats ? [
                'cpu_percent' => round((float) Arr::get($stats, 'utilization.cpu_absolute', 0), 1),
                'memory_mb' => (int) round(Arr::get($stats, 'utilization.memory_bytes', 0) / 1048576),
                'disk_mb' => (int) round(Arr::get($stats, 'utilization.disk_bytes', 0) / 1048576),
                'uptime_seconds' => (int) round($uptime / 1000),
                'started_at' => $uptime > 0
                    ? CarbonImmutable::now()->subMilliseconds($uptime)->startOfSecond()->toAtomString()
                    : null,
            ] : null,
            'limits' => [
                'memory_mb' => $server->memory ?: 'unlimited',
                'disk_mb' => $server->disk ?: 'unlimited',
                'cpu_percent' => $server->cpu ?: 'unlimited',
            ],
            'type' => $server->egg?->name,
            'docker_image' => $server->image,
            'addresses' => $server->allocations->map(fn (Allocation $a) => $this->address($a)
                .($a->id === $server->allocation_id ? ' (primary)' : ''))->values()->all(),
            // 0 means none, as in the panel.
            'feature_limits' => [
                'backups' => (int) $server->backup_limit,
                'databases' => (int) $server->database_limit,
                'ports' => (int) $server->allocation_limit,
            ],
            'your_permissions' => $this->permissions($server),
        ];

        if ($this->can(Permissions::StartupRead, $server)) {
            $out['startup_command'] = $server->startup;
            $out['startup_variables'] = $server->variables->where('user_viewable', true)
                ->mapWithKeys(fn (EggVariable $v) => [$v->env_variable => $v->server_value ?? $v->default_value])->all();
        }

        if ($this->can(Permissions::StartupDockerImage, $server) && ($choices = $this->imageChoices($server)) !== null) {
            $out['docker_image_choices'] = $choices;
        }

        if ($this->can(Permissions::FileSftp, $server)) {
            // Location only. SFTP signs in with the panel password, which is never handed out here.
            $out['sftp'] = [
                'host' => $server->node->fqdn,
                'port' => $server->node->daemonSFTP,
                'username' => "{$this->user->username}.{$server->uuidShort}",
            ];
        }

        return $out;
    }

    private function getConsole(array $args): string
    {
        $server = $this->server($args['server'], Permissions::WebsocketConnect);
        $lines = $this->consoleLines($server, $this->int($args, 'lines', self::CONSOLE_MAX_LINES));

        return $lines === [] ? '(no console output; the server may be offline)' : implode("\n", $lines);
    }

    private function listFiles(array $args): array
    {
        $server = $this->server($args['server'], Permissions::FileRead);
        $directory = $this->path($args['directory'] ?? '/');

        $entries = $this->directories->list($server, $directory);
        usort($entries, fn ($a, $b) => [$a['file'] ?? true, $a['name'] ?? ''] <=> [$b['file'] ?? true, $b['name'] ?? '']);

        return [
            'directory' => $directory,
            'entries' => array_map(fn ($e) => [
                'name' => ($e['name'] ?? '').(($e['file'] ?? true) ? '' : '/'),
                'size' => ($e['file'] ?? true) ? (int) ($e['size'] ?? 0) : null,
                'modified' => $e['modified'] ?? null,
            ], $entries),
        ];
    }

    private function readFile(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileReadContent);
        $path = $this->path($args['path']);

        $content = $this->reader->read($server, $path, self::MAX_READ_BYTES);
        $this->log($server, 'server:file.read', ['file' => $path]);

        if (str_contains(substr($content, 0, 8192), "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            throw new ToolException('That file is not text (binary or not UTF-8), so it cannot be shown.');
        }

        $all = preg_split('/\r\n|\n|\r/', $content);
        if (end($all) === '') {
            array_pop($all);
        }
        $total = count($all);

        // Keep line numbers attached before filtering, so matches can be found again.
        $lines = [];
        foreach ($all as $i => $text) {
            $lines[$i + 1] = $text;
        }

        $contains = $this->text($args, 'contains');
        if ($contains !== '') {
            $lines = array_filter($lines, fn ($l) => mb_stripos($l, $contains) !== false);
        }

        $max = max(1, min(5000, $this->int($args, 'max_lines', 500)));
        if ($this->bool($args, 'tail')) {
            $lines = array_slice($lines, -$max, null, true);
        } else {
            $start = max(1, $this->int($args, 'start_line', 1));
            $lines = array_slice(array_filter($lines, fn ($n) => $n >= $start, ARRAY_FILTER_USE_KEY), 0, $max, true);
        }

        $numbered = $contains !== '' || $this->bool($args, 'line_numbers');
        $body = '';
        $shown = 0;
        $last = null;
        foreach ($lines as $n => $text) {
            $row = ($numbered ? "{$n}: " : '').$text."\n";
            if (strlen($body) + strlen($row) > self::MAX_OUTPUT) {
                break;
            }
            $body .= $row;
            $shown++;
            $last = $n;
        }

        $first = array_key_first($lines);
        $header = $shown === 0
            ? "[{$path}: {$total} lines, nothing to show for this request]"
            : "[{$path}: {$total} lines".($contains !== '' ? ", {$shown} matching lines shown" : ", showing lines {$first}-{$last}")
                .($shown < count($lines) ? ', cut short by the size limit: ask again with start_line '.($last + 1) : '').']';

        return $header."\n".$body;
    }

    private function listSchedules(array $args): array
    {
        $server = $this->server($args['server'], Permissions::ScheduleRead);

        return $server->schedules->loadMissing('tasks')->map(fn (Schedule $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'cron' => "{$s->cron_minute} {$s->cron_hour} {$s->cron_day_of_month} {$s->cron_month} {$s->cron_day_of_week}",
            'active' => (bool) $s->is_active,
            'only_when_online' => (bool) $s->only_when_online,
            'last_run' => $s->last_run_at?->toAtomString(),
            'next_run' => $s->next_run_at?->toAtomString(),
            'tasks' => $s->tasks->sortBy('sequence_id')->map(fn (Task $t) => [
                'action' => $t->action,
                'payload' => $t->payload,
                'delay_seconds' => $t->time_offset,
            ])->values()->all(),
        ])->values()->all();
    }

    // ---------------------------------------------------------------------------------------
    // Control
    // ---------------------------------------------------------------------------------------

    private function powerAction(array $args): string
    {
        $action = strtolower($this->text($args, 'action'));
        if (! in_array($action, Task::POWER_ACTIONS, true)) {
            throw new ToolException('action must be one of: '.implode(', ', Task::POWER_ACTIONS));
        }

        $permission = Task::permissionForAction(Task::ACTION_POWER, $action);
        if ($permission === null) {
            throw new ToolException('action must be one of: '.implode(', ', Task::POWER_ACTIONS));
        }

        $server = $this->server($args['server'], $permission);
        $this->power->send($server, $action);
        $this->log($server, "server:power.{$action}");
        $this->forgetState($server);

        return "Sent \"{$action}\" to {$server->name}. Use get_server or get_console to follow it.";
    }

    private function sendCommand(array $args): string
    {
        $command = $this->text($args, 'command');
        if (preg_match('/[\r\n]/', $command) || strlen($command) > 2000) {
            throw new ToolException('command must be a single line of at most 2000 characters.');
        }
        $command = ltrim($command, '/');

        $server = $this->server($args['server'], Permissions::ControlConsole);

        try {
            $this->commands->send($server, $command);
        } catch (HttpExceptionInterface $e) {
            // DaemonConnectionException included: Wings answers 502 when the server is offline.
            if ($e->getStatusCode() === 502) {
                throw new ToolException('The server must be online to receive commands.');
            }
            throw $e;
        }
        $this->log($server, 'server:console.command', ['command' => $command]);

        $wait = max(0, min(10, $this->int($args, 'wait_seconds', 2)));
        if ($wait === 0 || ! $this->can(Permissions::WebsocketConnect, $server)) {
            return "Sent to {$server->name}: {$command}";
        }

        sleep($wait);
        try {
            $lines = $this->consoleLines($server, 40);
        } catch (DaemonConnectionException) {
            $lines = [];
        }

        return "Sent to {$server->name}: {$command}\n\nConsole {$wait}s later (most recent lines; other activity may be mixed in):\n".implode("\n", $lines);
    }

    private function runSchedule(array $args): string
    {
        $server = $this->server($args['server'], Permissions::ScheduleUpdate);
        $schedule = $this->schedule($server, $this->int($args, 'schedule_id', 0));

        $this->processor->process($schedule, true);
        $this->log($server, 'server:schedule.execute', ['name' => $schedule->name], $schedule);

        return "Schedule \"{$schedule->name}\" was started on {$server->name}.";
    }

    // ---------------------------------------------------------------------------------------
    // Change files
    // ---------------------------------------------------------------------------------------

    private function writeFile(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileCreate);
        $path = $this->path($args['path']);
        $content = $args['content'] ?? '';
        if (! is_string($content)) {
            throw new ToolException('content must be text.');
        }
        $this->assertWritable($content);

        $exists = $this->exists($server, $path);
        if ($exists && ! $this->bool($args, 'overwrite')) {
            throw new ToolException("{$path} already exists. Use edit_file to change part of it, or pass overwrite: true to replace the whole file.");
        }

        $this->writer->write($server, $path, $content);
        $this->log($server, 'server:file.write', ['file' => $path]);

        return ($exists ? 'Replaced ' : 'Created ')."{$path} on {$server->name} (".strlen($content).' bytes).';
    }

    private function editFile(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileCreate);
        if (! $this->can(Permissions::FileReadContent, $server)) {
            throw new ToolException('Your panel account may not read file contents on this server.');
        }

        $path = $this->path($args['path']);
        $old = $this->text($args, 'old_text');
        $new = $this->text($args, 'new_text');
        if ($old === $new) {
            throw new ToolException('old_text and new_text are the same.');
        }

        $content = $this->reader->read($server, $path, $this->maxEditSize());
        $count = substr_count($content, $old);
        if ($count === 0) {
            throw new ToolException('old_text was not found in the file. It must match exactly, including spaces and line breaks. Read the file again.');
        }
        if ($count > 1 && ! $this->bool($args, 'replace_all')) {
            throw new ToolException("old_text appears {$count} times. Include more surrounding text so it is unique, or pass replace_all: true.");
        }

        $updated = str_replace($old, $new, $content);
        $this->assertWritable($updated);
        $this->writer->write($server, $path, $updated);
        $this->log($server, 'server:file.write', ['file' => $path]);

        return "Edited {$path} on {$server->name}: {$count} replacement".($count === 1 ? '' : 's').'.';
    }

    private function createFolder(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileCreate);
        $path = $this->path($args['path']);
        if ($path === '/') {
            throw new ToolException('path must name a new folder.');
        }

        $this->folders->create($server, basename($path), $this->parent($path));
        $this->log($server, 'server:file.create-directory', ['name' => basename($path), 'directory' => $this->parent($path)]);

        return "Created folder {$path} on {$server->name}.";
    }

    private function renameFile(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileUpdate);
        $files = [['from' => ltrim($this->path($args['from']), '/'), 'to' => ltrim($this->path($args['to']), '/')]];
        if ($files[0]['from'] === '' || $files[0]['to'] === '') {
            throw new ToolException('from and to must both be paths inside the server.');
        }

        $this->renamer->rename($server, '/', $files);
        $this->log($server, 'server:file.rename', ['directory' => '/', 'files' => $files]);

        return "Moved /{$files[0]['from']} to /{$files[0]['to']} on {$server->name}.";
    }

    private function copyFile(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileCreate);
        $path = $this->path($args['path']);

        if (! isset($args['destination']) || $args['destination'] === '') {
            $this->copier->copy($server, $path);
            $this->log($server, 'server:file.copy', ['file' => $path]);

            return "Copied {$path} on {$server->name}. The copy sits next to it with \"copy\" in its name (pass destination to choose where it goes).";
        }

        $destination = $this->path($args['destination']);
        $source = $this->entry($server, $path);
        if (! $source || ! ($source['file'] ?? true)) {
            throw new ToolException("No file at {$path} (only files can be copied).");
        }
        if ($destination === $path || $destination === '/') {
            throw new ToolException('destination must be a different file path.');
        }
        $target = $this->entry($server, $destination);
        if ($target && ! ($target['file'] ?? true)) {
            throw new ToolException("{$destination} is a folder. Give the full path of the new file, e.g. {$destination}/".basename($path));
        }
        if ($target && ! $this->bool($args, 'overwrite')) {
            throw new ToolException("{$destination} already exists. Pass overwrite: true to replace it.");
        }

        // The node can only copy next to the original, so copy there and move the copy straight away.
        $dir = $this->parent($path);
        $before = array_column($this->directories->list($server, $dir), 'name');
        $this->copier->copy($server, $path);
        $created = array_values(array_diff(array_column($this->directories->list($server, $dir), 'name'), $before));
        if (count($created) !== 1) {
            throw new ToolException('The copy was made but could not be identified ('.count($created).' new files in '.$dir.'). Check that folder with list_files.');
        }
        $copy = ltrim(rtrim($dir, '/').'/'.$created[0], '/');

        $parked = null;
        try {
            if ($target) {
                $parked = ltrim($destination, '/').'.mcp-old-'.time();
                $this->renamer->rename($server, '/', [['from' => ltrim($destination, '/'), 'to' => $parked]]);
            }
            $this->renamer->rename($server, '/', [['from' => $copy, 'to' => ltrim($destination, '/')]]);
        } catch (DaemonConnectionException $e) {
            // Leave things as they were: no stray copy, old file back in place.
            try {
                $this->deleter->delete($server, '/', [$copy]);
                if ($parked) {
                    $this->renamer->rename($server, '/', [['from' => $parked, 'to' => ltrim($destination, '/')]]);
                }
            } catch (DaemonConnectionException) {
            }
            throw $e;
        }
        if ($parked) {
            $this->deleter->delete($server, '/', [$parked]);
        }

        $this->log($server, 'server:file.copy', ['file' => $path, 'destination' => $destination]);

        return "Copied {$path} to {$destination} on {$server->name}".($target ? ' (replacing the old file)' : '').'.';
    }

    private function deleteFiles(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileDelete);
        [$root, $names] = $this->rootAndNames($args);

        $this->deleter->delete($server, $root, $names);
        $this->log($server, 'server:file.delete', ['directory' => $root, 'files' => $names]);

        return 'Deleted from '.$root." on {$server->name}: ".implode(', ', $names);
    }

    private function compressFiles(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileArchive);
        [$root, $names] = $this->rootAndNames($args);

        set_time_limit(300);
        $file = $this->compressor->compress($server, $root, $names);
        $this->log($server, 'server:file.compress', ['directory' => $root, 'files' => $names]);

        return 'Created archive '.rtrim($root, '/').'/'.($file['name'] ?? '(unknown name)')." on {$server->name}.";
    }

    private function decompressFile(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileCreate);
        $path = $this->path($args['path']);

        set_time_limit(300);
        $this->decompressor->decompress($server, $this->parent($path), basename($path));
        $this->log($server, 'server:file.decompress', ['directory' => $this->parent($path), 'files' => basename($path)]);

        return "Unpacked {$path} into ".$this->parent($path)." on {$server->name}.";
    }

    private function pullFile(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileCreate);
        $url = $this->text($args, 'url');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            throw new ToolException('url must be an http or https address.');
        }

        $directory = $this->path($args['directory'] ?? '/');
        $filename = isset($args['filename']) && $args['filename'] !== '' ? basename($this->text($args, 'filename')) : null;

        set_time_limit(300);
        // Wings downloads in the foreground and answers when it is done. Wait up to 90 s for it (the
        // panel's 15 s default cut most plugin downloads short); proxies such as Cloudflare end requests at 100 s.
        $this->puller->pull($server, $url, $directory, ['filename' => $filename, 'foreground' => true, 'timeout' => 90]);
        $this->log($server, 'server:file.pull', ['directory' => $directory, 'url' => $url]);

        return "Downloaded {$url} into {$directory} on {$server->name}".($filename ? " as {$filename}" : '').'.';
    }

    // ---------------------------------------------------------------------------------------
    // More reading: activity and search
    // ---------------------------------------------------------------------------------------

    private function getActivity(array $args): array
    {
        $server = $this->server($args['server'], Permissions::ActivityRead);
        $limit = max(1, min(100, $this->int($args, 'limit', 25)));

        $query = $server->activity()->with('actor')
            ->whereNotIn('activity_logs.event', ActivityLog::DISABLED_EVENTS)
            ->orderByDesc('activity_logs.timestamp')
            ->orderByDesc('activity_logs.id');

        $event = trim($this->text($args, 'event'));
        if ($event !== '') {
            $query->where('activity_logs.event', 'like', '%'.addcslashes($event, '%_\\').'%');
        }

        // When admin activity is hidden, other accounts do not see it (root admins still do).
        $hideAdmins = config('activity.hide_admin_activity') && ! $this->user->root_admin;
        $members = $server->subusers->pluck('user_id')->push($server->owner_id);

        return $query->limit($hideAdmins ? $limit * 4 : $limit)->get()
            ->reject(fn (ActivityLog $a) => $hideAdmins && $a->actor instanceof User && $a->actor->root_admin && ! $members->contains($a->actor->id))
            ->take($limit)
            ->map(fn (ActivityLog $a) => array_filter([
                'time' => $a->timestamp->toAtomString(),
                'event' => $a->event,
                'by' => $a->actor instanceof User ? $a->actor->username : 'system',
                'ip' => $this->user->root_admin ? $a->ip : null,
                'details' => collect($a->properties)->except(['ip', 'useragent'])->all() ?: null,
            ], fn ($v) => $v !== null))
            ->values()->all();
    }

    private function searchFiles(array $args): string
    {
        $byName = $this->text($args, 'match', 'content') === 'name';
        $server = $this->server($args['server'], Permissions::FileRead);
        if (! $byName && ! $this->can(Permissions::FileReadContent, $server)) {
            throw new ToolException('Your panel account may not read file contents on this server.');
        }

        $query = $this->text($args, 'query');
        if (mb_strlen($query) < 2) {
            throw new ToolException('query must be at least 2 characters.');
        }
        $root = $this->path($args['directory'] ?? '/');
        $pattern = $this->text($args, 'name_pattern');
        $pattern = $pattern !== '' ? $pattern : null;
        $max = max(1, min(200, $this->int($args, 'max_results', 50)));
        $maxDepth = max(0, min(12, $this->int($args, 'max_depth', 8)));
        $folderLimit = $byName ? self::SEARCH_MAX_FOLDERS_BY_NAME : self::SEARCH_MAX_FOLDERS;
        $nameHits = [];
        // A name search stops once the folder level with the first match is done, unless asked to go on.
        $exhaustive = $this->bool($args, 'exhaustive');
        $hitDepth = null;

        set_time_limit(60);
        $deadline = microtime(true) + self::SEARCH_SECONDS;
        $queue = [[$root, 0]];
        $folders = $read = $tooLarge = 0;
        $hits = [];
        $stopped = null;

        while ($queue !== [] && $stopped === null) {
            if (microtime(true) > $deadline) {
                $stopped = 'the time limit';
                break;
            }
            if ($folders >= $folderLimit) {
                $stopped = "the limit of {$folderLimit} folders";
                break;
            }

            if ($byName && ! $exhaustive && $hitDepth !== null && $queue[0][1] > $hitDepth) {
                $stopped = 'shallow';
                break;
            }

            [$dir, $depth] = array_shift($queue);
            try {
                $entries = $this->directories->list($server, $dir);
            } catch (DaemonConnectionException $e) {
                if ($dir === $root) {
                    throw $e;
                }

                continue;
            }
            $folders++;

            foreach ($entries as $entry) {
                $name = (string) ($entry['name'] ?? '');
                $path = rtrim($dir, '/').'/'.$name;

                if (! ($entry['file'] ?? true)) {
                    if ($byName && mb_stripos($name, $query) !== false && count($hits) < $max) {
                        $hits[] = $path.'/ (folder)';
                        $hitDepth ??= $depth;
                    }
                    if ($depth < $maxDepth && empty($entry['symlink']) && ! in_array(strtolower($name), self::SEARCH_SKIP_FOLDERS, true)) {
                        $queue[] = [$path, $depth + 1];
                    }

                    continue;
                }

                // A text search also reports files whose name matches, e.g. a jar it cannot read.
                if (! $byName && count($nameHits) < 25 && mb_stripos($name, $query) !== false) {
                    $nameHits[] = $path.' ('.(int) ($entry['size'] ?? 0).' bytes)';
                }

                if ($pattern !== null && ! fnmatch($pattern, $name, FNM_CASEFOLD)) {
                    continue;
                }

                if ($byName) {
                    if (mb_stripos($name, $query) !== false) {
                        $hits[] = $path.' ('.(int) ($entry['size'] ?? 0).' bytes)';
                        $hitDepth ??= $depth;
                    }
                } else {
                    if ($pattern === null && ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::SEARCH_TEXT_TYPES, true)) {
                        continue;
                    }
                    if ((int) ($entry['size'] ?? 0) > self::SEARCH_MAX_FILE_BYTES) {
                        $tooLarge++;

                        continue;
                    }
                    if ($read >= self::SEARCH_MAX_FILES) {
                        $stopped = 'the limit of '.self::SEARCH_MAX_FILES.' files read';
                        break;
                    }
                    if (microtime(true) > $deadline) {
                        $stopped = 'the time limit';
                        break;
                    }

                    try {
                        $content = $this->reader->read($server, $path, self::SEARCH_MAX_FILE_BYTES);
                    } catch (DaemonConnectionException|FileSizeTooLargeException) {
                        continue;
                    }
                    $read++;
                    if (mb_stripos($content, $query) === false || str_contains(substr($content, 0, 4096), "\0")) {
                        continue;
                    }
                    foreach (preg_split('/\r\n|\n|\r/', $content) as $i => $line) {
                        if (mb_stripos($line, $query) !== false) {
                            $hits[] = $path.':'.($i + 1).': '.mb_substr(trim($line), 0, 200);
                            if (count($hits) >= $max) {
                                break;
                            }
                        }
                    }
                }

                if (count($hits) >= $max) {
                    $stopped = "the limit of {$max} results";
                    break;
                }
            }
        }

        $this->log($server, 'server:file.read', ['file' => "(search for \"{$query}\" in {$root})"]);

        $summary = count($hits).($byName ? ' file name' : ' line').(count($hits) === 1 ? '' : 's')." matching \"{$query}\" under {$root}"
            ." ({$folders} folders".($byName ? '' : ", {$read} files read").').'
            .match (true) {
                $stopped === 'shallow' => ' Stopped after the folder level where the first match was found; pass exhaustive: true to look through every subfolder as well.',
                $stopped !== null => " Stopped at {$stopped}, so there may be more: search a narrower folder or lower max_depth.",
                default => '',
            }
            .($tooLarge ? " {$tooLarge} files over 2 MB were skipped (use read_file with contains on those)." : '')
            .($byName || $pattern !== null ? '' : ' Only config-like text files were read; pass name_pattern (e.g. "*.log") for other types.');

        return $summary.($hits ? "\n".implode("\n", $hits) : '')
            .($nameHits ? "\n\nFiles whose name contains \"{$query}\":\n".implode("\n", $nameHits) : '');
    }

    // ---------------------------------------------------------------------------------------
    // Settings and schedules
    // ---------------------------------------------------------------------------------------

    private function setStartupVariable(array $args): string
    {
        $server = $this->server($args['server'], Permissions::StartupUpdate);
        /** @var EggVariable|null $variable */
        $variable = $server->variables()->where('env_variable', $this->text($args, 'key'))->first();

        if (! $variable || ! $variable->user_viewable) {
            throw new ToolException('No startup variable with that key. get_server lists them.');
        }
        if (! $variable->user_editable) {
            throw new ToolException('That startup variable is read-only.');
        }

        $value = $this->text($args, 'value');
        // Same rule parsing as the panel's own startup request: a regex rule is kept whole.
        $rules = (string) $variable->rules;
        Validator::make(['value' => $value], [
            'value' => $rules === '' ? [] : (Str::startsWith($rules, 'regex:') ? [$rules] : explode('|', $rules)),
        ])->validate();

        $old = $variable->server_value;
        $server->serverVariables()->updateOrCreate(
            ['variable_id' => $variable->id],
            ['variable_value' => $value]
        );
        if ($old !== $value) {
            $this->log($server, 'server:startup.edit', ['variable' => $variable->env_variable, 'old' => $old, 'new' => $value], $variable);
        }

        return "{$variable->env_variable} on {$server->name}: \"{$old}\" -> \"{$value}\". It applies the next time the server starts.";
    }

    /**
     * Root admins only (enforced by the catalog). The panel's own build and startup actions
     * validate, save and tell Wings, so nothing is left cached on the node.
     */
    private function updateServerConfig(array $args): string
    {
        $server = $this->server($args['server'], null);
        $changes = [];
        $notes = [];

        $build = [];
        foreach (['memory_mb' => 'memory', 'cpu_percent' => 'cpu', 'disk_mb' => 'disk'] as $arg => $column) {
            if (isset($args[$arg])) {
                if (! is_int($args[$arg]) && ! (is_string($args[$arg]) && ctype_digit($args[$arg]))) {
                    throw new ToolException("{$arg} must be a whole number (0 means unlimited).");
                }
                $value = (int) $args[$arg];
                if ($value < 0 || $value > 10000000) {
                    throw new ToolException("{$arg} is out of range (0 means unlimited).");
                }
                if ($value !== (int) $server->{$column}) {
                    $changes[$column] = ['old' => (int) $server->{$column}, 'new' => $value];
                    $build[$column] = $value;
                }
            }
        }

        $startup = [];
        if (isset($args['startup_command'])) {
            $command = trim($this->text($args, 'startup_command'));
            if ($command === '' || preg_match('/[\r\n]/', $command) || strlen($command) > 8000) {
                throw new ToolException('startup_command must be a single non-empty line.');
            }
            if ($command !== $server->startup) {
                $changes['startup'] = ['old' => $server->startup, 'new' => $command];
                $startup['startup'] = $command;
                if (str_contains($command, '*')) {
                    $notes[] = 'Warning: the command contains "*". The container entrypoint expands an unquoted * against file names, which has broken startups here before.';
                }
            }
        }
        if (isset($args['docker_image'])) {
            $image = trim($this->text($args, 'docker_image'));
            if (! preg_match('~^[\w.\-/:@]{1,191}$~', $image)) {
                throw new ToolException('docker_image does not look like an image name.');
            }
            if ($image !== $server->image) {
                $changes['image'] = ['old' => $server->image, 'new' => $image];
                $startup['docker_image'] = $image;
            }
        }

        if ($changes === []) {
            return "Nothing to change on {$server->name}: the values given are already in place.";
        }

        if ($build !== []) {
            // Every current limit is passed along: the action resets the feature limits it is not given.
            $server = $this->build->update($server, array_merge([
                'memory' => $server->memory, 'swap' => $server->swap, 'io' => $server->io, 'cpu' => $server->cpu,
                'threads' => $server->threads, 'disk' => $server->disk, 'oom_disabled' => (bool) $server->oom_disabled,
                'allocation_id' => $server->allocation_id, 'database_limit' => $server->database_limit,
                'allocation_limit' => $server->allocation_limit, 'backup_limit' => $server->backup_limit,
            ], $build));
            // The build action already told the node about the new limits.
        }

        if ($startup !== []) {
            $server = $this->startup->update($server, array_merge([
                'egg_id' => $server->egg_id, 'skip_scripts' => (bool) $server->skip_scripts,
            ], $startup), User::USER_LEVEL_ADMIN);
            try {
                // Wings caches the server configuration; without this the old one is used until Wings restarts.
                Daemon::server($server)->sync();
            } catch (DaemonConnectionException) {
                $notes[] = 'Warning: the node could not be told about the change. It will pick it up when the node next restarts.';
            }
        }

        $this->log($server, 'server:mcp.config-update', ['changes' => $changes]);
        $this->forgetState($server);

        $lines = [];
        foreach ($changes as $what => $c) {
            $lines[] = "- {$what}: {$c['old']} -> {$c['new']}";
        }

        return "Updated {$server->name}:\n".implode("\n", $lines)
            ."\nMemory and CPU limits apply to the running server now; the startup command and image apply the next time it starts."
            .($notes ? "\n".implode("\n", $notes) : '');
    }

    private function saveSchedule(array $args): string
    {
        $id = isset($args['schedule_id']) ? $this->int($args, 'schedule_id', 0) : null;
        $server = $this->server($args['server'], $id ? Permissions::ScheduleUpdate : Permissions::ScheduleCreate);
        $schedule = $id ? $this->schedule($server, $id) : null;

        if (! $schedule) {
            foreach (['name', 'minute', 'hour'] as $key) {
                if (! isset($args[$key]) || $args[$key] === '') {
                    throw new ToolException("A new schedule needs at least name, minute and hour (missing: {$key}).");
                }
            }
        }

        $pick = fn (string $arg, string $column, string $default) => isset($args[$arg]) ? trim($this->text($args, $arg)) : (string) ($schedule->{$column} ?? $default);
        $data = [
            'name' => $pick('name', 'name', ''),
            'minute' => $pick('minute', 'cron_minute', '*'),
            'hour' => $pick('hour', 'cron_hour', '*'),
            'day_of_month' => $pick('day_of_month', 'cron_day_of_month', '*'),
            'month' => $pick('month', 'cron_month', '*'),
            'day_of_week' => $pick('day_of_week', 'cron_day_of_week', '*'),
            'is_active' => array_key_exists('active', $args) ? $this->bool($args, 'active') : (bool) ($schedule->is_active ?? true),
            'only_when_online' => array_key_exists('only_when_online', $args) ? $this->bool($args, 'only_when_online') : (bool) ($schedule->only_when_online ?? false),
        ];

        if ($data['name'] === '' || mb_strlen($data['name']) > 191) {
            throw new ToolException('name must be 1 to 191 characters.');
        }
        foreach (['minute', 'hour', 'day_of_month', 'month', 'day_of_week'] as $field) {
            if ($data[$field] === '' || strlen($data[$field]) > 191) {
                throw new ToolException('Those time fields are not a valid cron expression.');
            }
        }

        // Same check core's schedule model makes when it computes next_run_at (upstream removed the
        // Utilities::getScheduleNextRunDate helper in 2fc24757).
        try {
            (new CronExpression(sprintf('%s %s %s %s %s', $data['minute'], $data['hour'], $data['day_of_month'], $data['month'], $data['day_of_week'])))->getNextRunDate();
        } catch (\Throwable) {
            throw new ToolException('Those time fields are not a valid cron expression.');
        }

        if ($schedule) {
            $schedule = $this->scheduleUpdater->update($schedule, $data);
            $this->log($server, 'server:schedule.update', ['name' => $schedule->name, 'active' => (bool) $schedule->is_active], $schedule);
        } else {
            $schedule = $this->scheduleCreator->create($server, $data);
            $this->log($server, 'server:schedule.create', ['name' => $schedule->name], $schedule);
        }

        $cron = "{$data['minute']} {$data['hour']} {$data['day_of_month']} {$data['month']} {$data['day_of_week']}";

        return ($id ? 'Updated' : 'Created')." schedule \"{$schedule->name}\" (id {$schedule->id}) on {$server->name}: cron \"{$cron}\" in the panel's timezone, "
            .($data['is_active'] ? 'active' : 'not active').', next run '.($schedule->next_run_at?->toAtomString() ?? 'unknown').'.'
            .($id ? '' : ' It has no tasks yet: add them with save_schedule_task.');
    }

    private function deleteSchedule(array $args): string
    {
        $server = $this->server($args['server'], Permissions::ScheduleDelete);
        $schedule = $this->schedule($server, $this->int($args, 'schedule_id', 0));
        $name = $schedule->name;

        $this->log($server, 'server:schedule.delete', ['name' => $name], $schedule);
        $this->scheduleDeleter->delete($schedule);

        return "Deleted schedule \"{$name}\" and its tasks from {$server->name}.";
    }

    private function saveScheduleTask(array $args): string
    {
        $server = $this->server($args['server'], Permissions::ScheduleUpdate);
        $schedule = $this->schedule($server, $this->int($args, 'schedule_id', 0));

        $task = null;
        if (isset($args['task_id'])) {
            $task = $schedule->tasks()->where('id', $this->int($args, 'task_id', 0))->first();
            if (! $task) {
                throw new ToolException('No task with that id in this schedule. Use list_schedules.');
            }
        }

        $action = isset($args['action']) ? $this->text($args, 'action') : (string) ($task?->action ?? '');
        $payload = isset($args['payload']) ? $this->text($args, 'payload') : (string) ($task?->payload ?? '');
        $delay = isset($args['delay_seconds']) ? $this->int($args, 'delay_seconds', 0) : (int) ($task?->time_offset ?? 0);
        $continue = array_key_exists('continue_on_failure', $args) ? $this->bool($args, 'continue_on_failure') : (bool) ($task?->continue_on_failure ?? false);

        if (! in_array($action, [Task::ACTION_COMMAND, Task::ACTION_POWER, Task::ACTION_BACKUP], true)) {
            throw new ToolException('action must be command, power or backup.');
        }
        if ($action === Task::ACTION_POWER && ! in_array($payload, Task::POWER_ACTIONS, true)) {
            throw new ToolException('For a power task, payload must be one of: '.implode(', ', Task::POWER_ACTIONS));
        }
        if ($action === Task::ACTION_COMMAND && ($payload === '' || preg_match('/[\r\n]/', $payload))) {
            throw new ToolException('For a command task, payload is the command (one line).');
        }
        if ($action === Task::ACTION_BACKUP && $server->backup_limit === 0) {
            throw new ToolException("A backup task cannot be created when the server's backup limit is 0.");
        }
        if ($delay < 0 || $delay > 900) {
            throw new ToolException('delay_seconds must be between 0 and 900.');
        }
        // Same rule as the panel: you can only schedule what you could do by hand.
        $needs = Task::permissionForAction($action, $payload);
        if ($needs !== null && ! $this->can($needs, $server)) {
            throw new ToolException("Your panel account does not have the \"{$needs->value}\" permission on {$server->name}.");
        }

        // No sequence_id: core appends a new task and keeps an updated task's place. Upstream's own input validation
        // (1866ce9b) refuses an explicit null, which older releases treated the same as leaving it out.
        $fields = ['action' => $action, 'payload' => $payload, 'time_offset' => $delay, 'continue_on_failure' => $continue];

        if ($task) {
            $task = $this->taskUpdater->update($schedule, $task, $fields);
            $this->log($server, 'server:task.update', ['name' => $schedule->name, 'action' => $action, 'payload' => $payload], $schedule, $task);

            return "Updated task {$task->id} (step {$task->sequence_id}) of \"{$schedule->name}\" on {$server->name}.";
        }

        $limit = (int) config('pterodactyl.client_features.schedules.per_schedule_task_limit', 10);
        if ($schedule->tasks()->count() >= $limit) {
            throw new ToolException("A schedule can have at most {$limit} tasks.");
        }

        $task = $this->taskCreator->create($schedule, $fields);
        $this->log($server, 'server:task.create', ['name' => $schedule->name, 'action' => $action, 'payload' => $payload], $schedule, $task);

        return "Added task {$task->id} as step {$task->sequence_id} of \"{$schedule->name}\" on {$server->name}.";
    }

    private function deleteScheduleTask(array $args): string
    {
        $server = $this->server($args['server'], Permissions::ScheduleUpdate);
        $schedule = $this->schedule($server, $this->int($args, 'schedule_id', 0));
        /** @var Task|null $task */
        $task = $schedule->tasks()->where('id', $this->int($args, 'task_id', 0))->first();
        if (! $task) {
            throw new ToolException('No task with that id in this schedule. Use list_schedules.');
        }

        $this->taskDeleter->delete($schedule, $task);
        $this->log($server, 'server:task.delete', ['name' => $schedule->name], $schedule, $task);

        return "Removed task {$task->id} from \"{$schedule->name}\" on {$server->name}.";
    }

    private function schedule(Server $server, int $id): Schedule
    {
        $schedule = $server->schedules()->whereKey($id)->first();
        if (! $schedule instanceof Schedule) {
            throw new ToolException('No schedule with that id on this server. Use list_schedules.');
        }

        return $schedule;
    }

    private function setDockerImage(array $args): string
    {
        $server = $this->server($args['server'], Permissions::StartupDockerImage);
        $choices = $this->imageChoices($server);
        if ($choices === null) {
            throw new ToolException("{$server->name}'s Docker image was set by an administrator, so only an administrator can change it"
                .($this->user->root_admin ? ' (update_server_config).' : '.'));
        }

        // A label ("Java 21") or the image itself.
        $wanted = trim($this->text($args, 'image'));
        $label = array_key_exists($wanted, $choices) ? $wanted : array_search($wanted, $choices, true);
        if ($label === false) {
            $list = implode(', ', array_map(fn ($l, $i) => "{$l} ({$i})", array_keys($choices), $choices));
            throw new ToolException("image must be one of: {$list}");
        }

        $image = $choices[$label];
        $old = $server->image;
        if ($image === $old) {
            return "{$server->name} already uses {$label} ({$image}).";
        }

        app(UpdatesServerDockerImage::class)->update($server, $image);
        $this->log($server, 'server:startup.image', ['old' => $old, 'new' => $image]);

        return "{$server->name} now uses {$label} ({$image}) instead of {$old}. It applies the next time the server starts.";
    }

    /**
     * The images a server's egg offers (label => image), or null when an administrator set an
     * image outside that list, which the panel then lets only administrators change.
     *
     * @return array<string, string>|null
     */
    private function imageChoices(Server $server): ?array
    {
        $choices = (array) ($server->egg?->docker_images ?? []);

        return in_array($server->image, $choices, true) ? $choices : null;
    }

    // ---------------------------------------------------------------------------------------
    // Backups
    // ---------------------------------------------------------------------------------------

    private function listBackups(array $args): array
    {
        $server = $this->server($args['server'], Permissions::BackupRead);
        $backups = $server->backups()->orderByDesc('created_at')->get();

        return [
            'limit' => $server->backup_limit ?: 'none (this server may not keep backups)',
            'kept' => $backups->filter(fn (Backup $b) => $this->backupState($b) !== 'failed')->count(),
            'backups' => $backups->map(fn (Backup $b) => array_filter([
                'id' => $b->uuid,
                'name' => $b->name,
                'state' => $this->backupState($b),
                'locked' => (bool) $b->is_locked,
                'size_mb' => $this->backupState($b) === 'completed' ? round($b->bytes / 1048576, 1) : null,
                'created_at' => $b->created_at?->toAtomString(),
                'completed_at' => $b->completed_at?->toAtomString(),
                'ignored' => $b->ignored_files ?: null,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    private function createBackup(array $args): string
    {
        $server = $this->server($args['server'], Permissions::BackupCreate);

        $ignore = $args['ignore'] ?? [];
        if (! is_array($ignore) || ! array_is_list($ignore) || array_filter($ignore, fn ($f) => ! is_string($f)) !== []) {
            throw new ToolException('ignore must be a list of file or folder patterns.');
        }

        // As in the panel: only someone who may delete backups may lock one, or anyone could fill
        // the server with backups nobody can remove.
        // Locking also needs the Manage scope, which alone can unlock: a Control-only connection
        // could otherwise fill every backup slot with backups it cannot remove.
        $locked = $this->bool($args, 'locked');
        if ($locked && ! in_array(OAuthService::SCOPE_MANAGE, $this->grant->scopes, true)) {
            $title = OAuthService::SCOPES[OAuthService::SCOPE_MANAGE]['title'];
            throw new ToolException("Locking a backup needs a connection given \"{$title}\" access. Try again without locked.");
        }
        if ($locked && ! $this->can(Permissions::BackupDelete, $server)) {
            throw new ToolException("Locking a backup needs the \"backup.delete\" permission on {$server->name}. Try again without locked.");
        }

        $name = trim($this->text($args, 'name'));
        $backup = app(InitiatesBackups::class)
            ->setIgnoredFiles($ignore)
            ->setIsLocked($locked)
            ->initiate($server, $name !== '' ? $name : null);
        $this->log($server, 'server:backup.start', ['name' => $backup->name, 'locked' => (bool) $backup->is_locked], $backup);

        return "Started backup \"{$backup->name}\" (id {$backup->uuid}) of {$server->name}".($backup->is_locked ? ', locked' : '')
            .'. The node makes it in the background; list_backups shows when it has finished.';
    }

    private function restoreBackup(array $args): string
    {
        $server = $this->server($args['server'], Permissions::BackupRestore);
        $backup = $this->backup($server, $args['backup']);
        if (($state = $this->backupState($backup)) !== 'completed') {
            throw new ToolException("Backup \"{$backup->name}\" is {$state}, so it cannot be restored.");
        }

        $truncate = $this->bool($args, 'delete_existing_files');
        $this->throttle($server, ResourceLimit::Backup);
        app(RestoresBackups::class)->restore($server, $backup, $this->user, $truncate);
        $this->log($server, 'server:backup.restore', ['name' => $backup->name, 'truncate' => $truncate], $backup);
        $this->forgetState($server);

        return "Restoring backup \"{$backup->name}\" on {$server->name}".($truncate ? ' after deleting every current file' : ' over the current files')
            .'. The node stops the server first and leaves it stopped. get_server shows "restoring a backup" until it is done; then start it with power_action.';
    }

    private function deleteBackup(array $args): string
    {
        $server = $this->server($args['server'], Permissions::BackupDelete);
        $backup = $this->backup($server, $args['backup']);
        if ($backup->is_locked && $this->backupState($backup) === 'completed') {
            throw new ToolException("Backup \"{$backup->name}\" is locked. Unlock it with set_backup_lock first.");
        }

        app(DeletesBackups::class)->delete($backup);
        $this->log($server, 'server:backup.delete', ['name' => $backup->name, 'failed' => $this->backupState($backup) === 'failed'], $backup);

        return "Deleted backup \"{$backup->name}\" of {$server->name}.";
    }

    private function setBackupLock(array $args): string
    {
        // The panel asks for the delete permission to lock or unlock.
        $server = $this->server($args['server'], Permissions::BackupDelete);
        $backup = $this->backup($server, $args['backup']);
        $locked = $this->bool($args, 'locked');
        if ((bool) $backup->is_locked === $locked) {
            return "Backup \"{$backup->name}\" is already ".($locked ? 'locked' : 'unlocked').'.';
        }
        if ($locked && $this->backupState($backup) === 'failed') {
            throw new ToolException("Backup \"{$backup->name}\" failed, so there is nothing to lock.");
        }

        app(TogglesBackupLocks::class)->toggle($backup);
        $this->log($server, $locked ? 'server:backup.lock' : 'server:backup.unlock', ['name' => $backup->name], $backup);

        return "Backup \"{$backup->name}\" of {$server->name} is now ".($locked ? 'locked: it cannot be deleted until it is unlocked.' : 'unlocked.');
    }

    private function downloadBackup(array $args): string
    {
        $server = $this->server($args['server'], Permissions::BackupDownload);
        $backup = $this->backup($server, $args['backup']);
        if (($state = $this->backupState($backup)) !== 'completed') {
            throw new ToolException("Backup \"{$backup->name}\" is {$state}, so there is nothing to download.");
        }

        $url = app(GeneratesBackupDownloadLinks::class)->generate($backup, $this->user);
        $this->log($server, 'server:backup.download', ['name' => $backup->name], $backup);

        // Wings links last 15 minutes, S3 ones 5 (GenerateBackupDownloadLink).
        $minutes = $backup->disk === Backup::ADAPTER_AWS_S3 ? 5 : 15;

        return "Download link for backup \"{$backup->name}\" of {$server->name} (".round($backup->bytes / 1048576, 1)." MB, .tar.gz), valid for {$minutes} minutes. "
            ."Anyone holding it can download the backup until then, so give it only to the user.\n\n{$url}";
    }

    /**
     * One of the server's backups, by id (UUID) or exact name.
     */
    private function backup(Server $server, mixed $ref): Backup
    {
        $ref = trim(is_scalar($ref) ? (string) $ref : '');
        $matches = $server->backups()->where('uuid', $ref)->get();
        if ($matches->isEmpty()) {
            $matches = $server->backups()->where('name', $ref)->get();
        }

        if ($matches->isEmpty()) {
            throw new ToolException("No backup \"{$ref}\" on {$server->name}. Use list_backups.");
        }
        if ($matches->count() > 1) {
            throw new ToolException("More than one backup is named \"{$ref}\". Pass its id from list_backups instead.");
        }

        return $matches->first();
    }

    private function backupState(Backup $backup): string
    {
        return match (true) {
            $backup->completed_at === null => 'in progress',
            (bool) $backup->is_successful => 'completed',
            default => 'failed',
        };
    }

    // ---------------------------------------------------------------------------------------
    // Network
    // ---------------------------------------------------------------------------------------

    private function listAllocations(array $args): array
    {
        $server = $this->server($args['server'], Permissions::AllocationRead);

        return [
            'limit' => $server->allocation_limit ?: 'none (ports cannot be added or removed here)',
            'allocations' => $server->allocations()->orderBy('port')->get()->map(fn (Allocation $a) => array_filter([
                'id' => $a->id,
                'address' => $this->address($a),
                'ip' => $a->ip,
                'port' => $a->port,
                'notes' => $a->notes,
                'primary' => $a->id === $server->allocation_id,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    private function addAllocation(array $args): string
    {
        $server = $this->server($args['server'], Permissions::AllocationCreate);
        $notes = $this->notes($args);

        $this->throttle($server, ResourceLimit::Allocation);
        $allocation = app(AssignsAllocations::class)->assign($server);
        $this->log($server, 'server:allocation.create', ['allocation' => $allocation->toString()], $allocation);
        if ($notes !== null) {
            app(UpdatesAllocationNotes::class)->update($allocation, $notes);
        }

        return "Added {$this->address($allocation)} (id {$allocation->id}) to {$server->name}. "
            .'The node opens it the next time the server starts, and the game or plugin must be set to listen on that port.';
    }

    private function updateAllocation(array $args): string
    {
        $primary = $this->bool($args, 'primary');
        if (! array_key_exists('notes', $args) && ! $primary) {
            throw new ToolException('Pass notes, primary: true, or both.');
        }

        $server = $this->server($args['server'], Permissions::AllocationUpdate);
        $allocation = $this->allocation($server, $args['allocation']);
        $address = $this->address($allocation);
        $done = [];

        if (array_key_exists('notes', $args)) {
            $notes = $this->notes($args);
            if ($notes !== $allocation->notes) {
                $old = $allocation->notes;
                app(UpdatesAllocationNotes::class)->update($allocation, $notes);
                $this->log($server, 'server:allocation.notes', ['allocation' => $allocation->toString(), 'old' => $old, 'new' => $notes], $allocation);
            }
            $done[] = $notes === null ? 'notes cleared' : "notes \"{$notes}\"";
        }

        if ($primary) {
            if ($allocation->id === $server->allocation_id) {
                $done[] = 'already the primary port';
            } else {
                app(SetsPrimaryAllocations::class)->setPrimary($server, $allocation);
                $this->log($server, 'server:allocation.primary', ['allocation' => $allocation->toString()], $allocation);
                $done[] = 'now the primary port (players connect here after the next start)';
            }
        }

        return "{$address} on {$server->name}: ".implode('; ', $done).'.';
    }

    private function removeAllocation(array $args): string
    {
        $server = $this->server($args['server'], Permissions::AllocationDelete);
        $allocation = $this->allocation($server, $args['allocation']);
        $address = $this->address($allocation);
        if ($allocation->id === $server->allocation_id) {
            throw new ToolException("{$address} is the primary port of {$server->name}. Make another port primary first.");
        }

        app(UnassignsAllocations::class)->unassign($server, $allocation);
        $this->log($server, 'server:allocation.delete', ['allocation' => $allocation->toString()], $allocation);

        return "Removed {$address} from {$server->name}. The node closes it the next time the server starts.";
    }

    /**
     * One of the server's allocations, by id or address ("ip:port", or the alias and port).
     */
    private function allocation(Server $server, mixed $ref): Allocation
    {
        $ref = trim(is_scalar($ref) ? (string) $ref : '');
        $allocation = $server->allocations()->get()
            ->first(fn (Allocation $a) => (string) $a->id === $ref || $a->toString() === $ref || $this->address($a) === $ref);

        return $allocation ?? throw new ToolException("{$server->name} has no port \"{$ref}\". Use list_allocations and pass its id.");
    }

    private function address(Allocation $allocation): string
    {
        return ($allocation->ip_alias ?: $allocation->ip).':'.$allocation->port;
    }

    /**
     * The notes argument: null when absent or blank (which clears them), at most 256 characters
     * as in the panel.
     */
    private function notes(array $args): ?string
    {
        $notes = trim($this->text($args, 'notes'));
        if (mb_strlen($notes) > 256) {
            throw new ToolException('notes may be at most 256 characters.');
        }

        return $notes === '' ? null : $notes;
    }

    // ---------------------------------------------------------------------------------------
    // Databases
    // ---------------------------------------------------------------------------------------

    private function listDatabases(array $args): array
    {
        $server = $this->server($args['server'], Permissions::DatabaseRead);
        $passwords = $this->bool($args, 'include_passwords');
        if ($passwords) {
            if (! in_array(OAuthService::SCOPE_MANAGE, $this->grant->scopes, true)) {
                $title = OAuthService::SCOPES[OAuthService::SCOPE_MANAGE]['title'];
                throw new ToolException("Passwords are only shown to connections given \"{$title}\" access. Ask again without include_passwords.");
            }
            if (! $this->can(Permissions::DatabaseViewPassword, $server)) {
                throw new ToolException("Your panel account does not have the \"database.view_password\" permission on {$server->name}.");
            }
        }

        return [
            'limit' => $server->database_limit ?: 'none (this server may not have databases)',
            'databases' => $server->databases()->with('host')->get()
                ->map(fn (Database $d) => $this->databaseDetails($d, $passwords))->values()->all(),
        ];
    }

    private function createDatabase(array $args): string
    {
        $server = $this->server($args['server'], Permissions::DatabaseCreate);
        // The action's own refusal is not a DisplayException, so it would surface as an internal error.
        if (! config('pterodactyl.client_features.databases.enabled')) {
            throw new ToolException('This panel does not let users create databases.');
        }
        $name = trim($this->text($args, 'name'));
        // The panel checks this in its request, before the action, so the database server is never asked.
        if ($server->databases()->where('database', DatabaseName::generateUnique($name, $server->id))->exists()) {
            throw new ToolException("{$server->name} already has a database called {$name}.");
        }

        $this->throttle($server, ResourceLimit::Database);
        $database = app(DeploysServerDatabases::class)->deploy($server, [
            'database' => $name,
            'remote' => trim($this->text($args, 'connections_from', '%')),
        ]);
        $this->log($server, 'server:database.create', ['name' => $database->database], $database);

        return "Created database {$database->database} for {$server->name}.\n".$this->databaseText($server, $database);
    }

    private function rotateDatabasePassword(array $args): string
    {
        $server = $this->server($args['server'], Permissions::DatabaseUpdate);
        $database = $this->database($server, $args['database']);

        app(RotatesDatabasePasswords::class)->rotate($database);
        $this->log($server, 'server:database.rotate-password', ['name' => $database->database], $database);

        return "New password for {$database->database} on {$server->name}. Anything still using the old one stops connecting until its config is updated.\n"
            .$this->databaseText($server, $database->refresh());
    }

    private function deleteDatabase(array $args): string
    {
        $server = $this->server($args['server'], Permissions::DatabaseDelete);
        $database = $this->database($server, $args['database']);

        app(DeletesDatabases::class)->delete($database);
        $this->log($server, 'server:database.delete', ['name' => $database->database], $database);

        return "Deleted database {$database->database} of {$server->name}, its user and all of its data.";
    }

    /**
     * One of the server's databases, by its full name or the name it was created with (the panel
     * adds an "s<server id>_" prefix).
     */
    private function database(Server $server, mixed $ref): Database
    {
        $ref = trim(is_scalar($ref) ? (string) $ref : '');
        $database = $server->databases()->where('database', $ref)->first()
            ?? $server->databases()->where('database', DatabaseName::generateUnique($ref, $server->id))->first();

        return $database ?? throw new ToolException("{$server->name} has no database \"{$ref}\". Use list_databases.");
    }

    private function databaseDetails(Database $database, bool $password): array
    {
        $database->loadMissing('host');

        return array_filter([
            'name' => $database->database,
            'host' => $database->host->host.':'.$database->host->port,
            'username' => $database->username,
            'password' => $password ? Crypt::decrypt($database->password) : null,
            'connections_from' => $database->remote,
            'max_connections' => $database->max_connections ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Connection details after a create or rotate. The password is left out, as in the panel, for
     * accounts without database.view_password.
     */
    private function databaseText(Server $server, Database $database): string
    {
        $show = $this->can(Permissions::DatabaseViewPassword, $server);
        $text = '';
        foreach ($this->databaseDetails($database, $show) as $key => $value) {
            $text .= "{$key}: {$value}\n";
        }

        return $text.($show
            ? "\nThe password is meant for the config that uses this database: write it there, do not repeat it in chat."
            : "\nYour account may not view database passwords on this server, so it is not shown.");
    }

    // ---------------------------------------------------------------------------------------
    // Server settings
    // ---------------------------------------------------------------------------------------

    private function renameServer(array $args): string
    {
        if (! array_key_exists('name', $args) && ! array_key_exists('description', $args)) {
            throw new ToolException('Pass name, description, or both.');
        }

        $server = $this->server($args['server'], Permissions::SettingsRename);
        $old = ['name' => $server->name, 'description' => (string) $server->description];
        $new = [
            'name' => array_key_exists('name', $args) ? trim($this->text($args, 'name')) : $old['name'],
            'description' => array_key_exists('description', $args) ? trim($this->text($args, 'description')) : $old['description'],
        ];
        if ($new === $old) {
            return "Nothing to change on {$server->name}: the values given are already in place.";
        }

        // The action replaces the owner and external id too, so the current ones are passed along.
        $server = app(UpdatesServerDetails::class)->update($server, [
            'external_id' => $server->external_id,
            'owner_id' => $server->owner_id,
            ...$new,
        ]);

        $lines = [];
        foreach (['name' => 'server:settings.rename', 'description' => 'server:settings.description'] as $field => $event) {
            if ($new[$field] !== $old[$field]) {
                $this->log($server, $event, ['old' => $old[$field], 'new' => $new[$field]]);
                $lines[] = "- {$field}: \"{$old[$field]}\" -> \"{$new[$field]}\"";
            }
        }

        return "Updated {$server->name}:\n".implode("\n", $lines);
    }

    private function reinstallServer(array $args): string
    {
        $server = $this->server($args['server'], Permissions::SettingsReinstall);

        app(ReinstallsServers::class)->reinstall($server);
        $this->log($server, 'server:reinstall');
        $this->forgetState($server);

        return "Reinstalling {$server->name}: the node stops it and runs its install script again. "
            .'get_server shows "still installing" until the script has finished; then start it with power_action.';
    }

    /**
     * The panel's per-server limit on a resource, counted in the bucket its client API uses (the
     * key Laravel's ThrottleRequests gives a named limiter), so the panel and MCP share one allowance.
     */
    private function throttle(Server $server, ResourceLimit $resource): void
    {
        $limit = $resource->limit();
        $key = md5($resource->throttleKey().$server->uuid);
        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            throw new ToolException("That was done too often on {$server->name} just now. Try again in ".RateLimiter::availableIn($key).' seconds.');
        }
        RateLimiter::hit($key, $limit->decaySeconds);
    }

    // ---------------------------------------------------------------------------------------
    // Binary files, archives and waiting for the console
    // ---------------------------------------------------------------------------------------

    private function uploadFile(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileCreate);
        $path = $this->path($args['path']);
        if ($path === '/') {
            throw new ToolException('path must name a file.');
        }

        $encoded = preg_replace('/\s+/', '', $this->text($args, 'content_base64')) ?? '';
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new ToolException('content_base64 is not valid base64.');
        }
        if (strlen($bytes) > self::UPLOAD_MAX_BYTES) {
            throw new ToolException('That is over '.(self::UPLOAD_MAX_BYTES / 1048576).' MB. Use create_upload_link for bigger files.');
        }

        $existing = $this->entry($server, $path);
        if ($existing && ! ($existing['file'] ?? true)) {
            throw new ToolException("{$path} is a folder.");
        }
        if ($existing && ! $this->bool($args, 'overwrite')) {
            throw new ToolException("{$path} already exists. Pass overwrite: true to replace it.");
        }

        $this->writer->write($server, $path, $bytes);
        $hash = hash('sha256', $bytes);
        $this->log($server, 'server:file.write', ['file' => $path, 'bytes' => strlen($bytes), 'sha256' => $hash]);

        return ($existing ? 'Replaced ' : 'Uploaded ')."{$path} on {$server->name}: ".strlen($bytes)." bytes, sha256 {$hash}.";
    }

    private function createUploadLink(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileCreate);
        $directory = $this->path($args['directory'] ?? '/');

        $token = $this->nodeToken($server, JwtScope::FileUpload, ['server_uuid' => $server->uuid]);

        $url = sprintf('%s/upload/file?token=%s&directory=%s', $server->node->getConnectionAddress(), $token, rawurlencode($directory));
        $this->log($server, 'server:file.upload-link', ['directory' => $directory]);

        return "Upload link for {$directory} on {$server->name}, valid for 15 minutes, up to {$server->node->upload_size} MB per file. "
            ."Anyone holding it can upload files to this server until it expires (the folder is part of the URL and can be changed), so do not share it. Files with the same name are replaced.\n\n"
            ."curl -sS -F \"files=@/path/to/local.file\" \"{$url}\"\n\n"
            .'Repeat -F files=@... for several files. Check the result with list_files afterwards.';
    }

    private function downloadFile(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileReadContent);
        $path = $this->path($args['path']);
        $entry = $this->entry($server, $path);
        if (! $entry || ! ($entry['file'] ?? true)) {
            throw new ToolException("No file at {$path}.");
        }

        $size = (int) ($entry['size'] ?? 0);
        $as = $this->text($args, 'as', $size <= self::BASE64_MAX_BYTES ? 'base64' : 'link');
        if (! in_array($as, ['base64', 'link'], true)) {
            throw new ToolException('as must be "base64" or "link".');
        }

        if ($as === 'base64') {
            if ($size > self::BASE64_MAX_BYTES) {
                throw new ToolException("{$path} is {$size} bytes; base64 is limited to ".(self::BASE64_MAX_BYTES / 1024).' KB. Ask for as: "link", or use inspect_archive for a jar.');
            }
            $bytes = $this->reader->read($server, $path, self::BASE64_MAX_BYTES);
            $this->log($server, 'server:file.download', ['file' => $path]);

            return "{$path}: ".strlen($bytes).' bytes, sha256 '.hash('sha256', $bytes).". Base64 follows on the next line.\n".base64_encode($bytes);
        }

        $token = $this->nodeToken($server, JwtScope::FileDownload, ['file_path' => $path, 'server_uuid' => $server->uuid]);
        $this->log($server, 'server:file.download', ['file' => $path]);

        return "Download link for {$path} ({$size} bytes) on {$server->name}, valid for 15 minutes. Anyone holding it can download the file until then.\n\n"
            .sprintf('%s/download/file?token=%s', $server->node->getConnectionAddress(), $token);
    }

    /**
     * A 15-minute Wings token for one file operation. Upstream replaced NodeJWTService with NodeJwt::issue() in
     * 94c31152 (2026-10-12); both are supported so this extension also runs on the releases a rollback goes back to.
     */
    private function nodeToken(Server $server, JwtScope $scope, array $claims): string
    {
        $expires = CarbonImmutable::now()->addMinutes(15);
        $identifiedBy = $this->user->id.$server->uuid;
        $jwt = 'Pterodactyl\\Services\\Nodes\\NodeJwt';
        if (class_exists($jwt)) {
            return app($jwt)
                ->issue($server->node, $scope, identifiedBy: $identifiedBy, expiresAt: $expires, user: $this->user, claims: $claims)
                ->toString();
        }

        return app('Pterodactyl\\Services\\Nodes\\NodeJWTService')
            ->setExpiresAt($expires)
            ->setUser($this->user)
            ->setClaims($claims)
            ->setScopes($scope)
            ->handle($server->node, $identifiedBy)
            ->toString();
    }

    private function inspectArchive(array $args): string
    {
        $server = $this->server($args['server'], Permissions::FileReadContent);
        $path = $this->path($args['path']);
        if (! class_exists(\ZipArchive::class)) {
            throw new ToolException('The panel host has no zip support.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'lmcp');
        if ($tmp === false) {
            throw new ToolException('The panel could not create a temporary file.');
        }
        set_time_limit(180);
        try {
            try {
                $this->wings->downloadTo($server, $path, $tmp, self::ARCHIVE_MAX_BYTES);
            } catch (\OverflowException) {
                throw new ToolException('That archive is over '.(self::ARCHIVE_MAX_BYTES / 1048576).' MB, too big to open here.');
            }

            $zip = new \ZipArchive;
            if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) {
                throw new ToolException("{$path} is not a zip or jar archive.");
            }

            try {
                $entry = isset($args['entry']) ? ltrim($this->text($args, 'entry'), '/') : '';
                $this->log($server, 'server:file.read', ['file' => $entry === '' ? $path : "{$path}!/{$entry}"]);

                return $entry === '' ? $this->archiveListing($zip, $path, $this->text($args, 'filter')) : $this->archiveEntry($zip, $path, $entry);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function archiveListing(\ZipArchive $zip, string $path, string $filter): string
    {
        $rows = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! $stat || str_ends_with($stat['name'], '/')) {
                continue;
            }
            $total++;
            if ($filter === '' || mb_stripos($stat['name'], $filter) !== false) {
                $rows[] = sprintf('%10d  %s', $stat['size'], $stat['name']);
            }
        }

        $out = "{$path}: {$total} files".($filter !== '' ? ', '.count($rows)." matching \"{$filter}\"" : '').".\n";

        if ($filter === '') {
            foreach (['plugin.yml', 'paper-plugin.yml', 'bungee.yml', 'velocity-plugin.json', 'fabric.mod.json', 'META-INF/mods.toml', 'META-INF/neoforge.mods.toml'] as $descriptor) {
                $text = $zip->getFromName($descriptor);
                if (is_string($text) && $text !== '') {
                    $out .= "\n--- {$descriptor} ---\n".mb_strcut($text, 0, 8000)."\n--- end ---\n";
                }
            }
            $manifest = $zip->getFromName('META-INF/MANIFEST.MF');
            if (is_string($manifest) && preg_match('/^Main-Class:\s*(\S+)/mi', $manifest, $m)) {
                $out .= "Main-Class: {$m[1]}\n";
            }
            $out .= "\n";
        }

        $shown = array_slice($rows, 0, 1500);
        $out .= "      size  name\n".implode("\n", $shown);
        if (count($rows) > count($shown)) {
            $out .= "\n… ".(count($rows) - count($shown)).' more; pass filter to narrow it down.';
        }

        return $this->capOutput($out);
    }

    private function archiveEntry(\ZipArchive $zip, string $path, string $entry): string
    {
        $stat = $zip->statName($entry);
        if (! $stat) {
            throw new ToolException("No {$entry} inside {$path}. Call inspect_archive without entry (or with filter) to list what is there.");
        }
        if ($stat['size'] > self::ARCHIVE_ENTRY_MAX_BYTES) {
            throw new ToolException("{$entry} is {$stat['size']} bytes unpacked, too big to show.");
        }

        $bytes = (string) $zip->getFromIndex($stat['index']);
        if (str_ends_with(strtolower($entry), '.class')) {
            return $this->capOutput("{$path}!/{$entry} ({$stat['size']} bytes), outline only (no method bodies):\n\n".JavaClassSummary::describe($bytes));
        }
        if (! str_contains(substr($bytes, 0, 8192), "\0") && mb_check_encoding($bytes, 'UTF-8')) {
            return $this->capOutput("{$path}!/{$entry} ({$stat['size']} bytes):\n".$bytes);
        }
        if (strlen($bytes) <= self::BASE64_MAX_BYTES) {
            return "{$path}!/{$entry}: binary, {$stat['size']} bytes, sha256 ".hash('sha256', $bytes).". Base64 follows on the next line.\n".base64_encode($bytes);
        }

        throw new ToolException("{$entry} is binary and {$stat['size']} bytes; too big to return as base64.");
    }

    private function waitForConsole(array $args): string
    {
        $server = $this->server($args['server'], Permissions::WebsocketConnect);
        $needle = $this->text($args, 'text');
        $isRegex = $this->bool($args, 'regex');
        if ($needle === '' || mb_strlen($needle) > 200) {
            throw new ToolException('text must be 1 to 200 characters.');
        }
        $pattern = '/'.str_replace('/', '\/', $needle).'/iu';
        if ($isRegex && @preg_match($pattern, '') === false) {
            throw new ToolException('text is not a valid regular expression.');
        }
        $matches = $isRegex
            ? fn (string $line) => preg_match($pattern, $line) === 1
            : fn (string $line) => mb_stripos($line, $needle) !== false;

        $timeout = max(5, min(self::WAIT_MAX_SECONDS, $this->int($args, 'timeout_seconds', 60)));
        $existing = $this->bool($args, 'include_existing');

        // One wait per account at a time: each one holds a web worker for up to a minute and a half.
        $lock = Cache::lock("panel-mcp:wait:{$this->user->id}", self::WAIT_MAX_SECONDS + 15);
        if (! $lock->get()) {
            throw new ToolException('Another wait_for_console from this account is still running. Wait for it to finish.');
        }

        set_time_limit(self::WAIT_MAX_SECONDS + 30);
        $started = microtime(true);
        try {
            // Two sources: the console buffer (last 100 lines, any server type) and logs/latest.log
            // (complete, so nothing is missed when a burst scrolls past the buffer between checks).
            $useLog = $this->can(Permissions::FileReadContent, $server);
            $logOffset = $existing ? 0 : (int) ($this->entry($server, '/logs/latest.log')['size'] ?? 0);
            $previous = $existing ? [] : $this->safeConsole($server);
            $poll = 0;

            while (true) {
                $lines = $this->safeConsole($server);
                foreach ($this->newLines($previous, $lines) as $line) {
                    if ($matches($line)) {
                        return $this->waitResult($server, $line, 'console', $started);
                    }
                }
                $previous = $lines;

                $last = microtime(true) - $started >= $timeout;
                if ($useLog && ($poll % 3 === 0 || $last)) {
                    $entry = $this->entry($server, '/logs/latest.log');
                    $size = (int) ($entry['size'] ?? 0);
                    if ($entry && $size < $logOffset) {
                        $logOffset = 0; // the server restarted and the log was rotated
                    }
                    if ($entry && $size > $logOffset && $size <= self::MAX_READ_BYTES) {
                        try {
                            $content = $this->reader->read($server, '/logs/latest.log', self::MAX_READ_BYTES);
                        } catch (DaemonConnectionException|FileSizeTooLargeException) {
                            $content = '';
                        }
                        $fresh = substr($content, $logOffset);
                        $cut = strrpos($fresh, "\n");
                        if ($cut !== false) {
                            foreach (explode("\n", substr($fresh, 0, $cut)) as $line) {
                                if ($matches($line)) {
                                    return $this->waitResult($server, $line, 'logs/latest.log', $started);
                                }
                            }
                            $logOffset += $cut + 1;
                        }
                    }
                }

                if ($last) {
                    break;
                }
                $poll++;
                usleep(2000000);
            }
        } finally {
            $lock->release();
        }

        $state = $this->stateOf($server);

        return 'Not seen within '.round(microtime(true) - $started)." s: \"{$needle}\". {$server->name} is {$state}."
            .' Call wait_for_console again to keep waiting (new lines only), or get_console to look.';
    }

    /**
     * Lines in $now that came after $before. The console buffer is a sliding window, so this finds
     * where the old window's tail sits in the new one.
     *
     * @return string[]
     */
    private function newLines(array $before, array $now): array
    {
        if ($before === []) {
            return $now;
        }
        for ($take = min(5, count($before)); $take >= 1; $take--) {
            $tail = array_slice($before, -$take);
            for ($i = count($now) - $take; $i >= 0; $i--) {
                if (array_slice($now, $i, $take) === $tail) {
                    return array_slice($now, $i + $take);
                }
            }
        }

        return $now;
    }

    private function safeConsole(Server $server): array
    {
        try {
            return $this->consoleLines($server, self::CONSOLE_MAX_LINES);
        } catch (DaemonConnectionException) {
            return [];
        }
    }

    private function waitResult(Server $server, string $line, string $source, float $started): string
    {
        return 'Seen after '.round(microtime(true) - $started, 1)." s in the {$source} of {$server->name}:\n".mb_substr(trim($line), 0, 500);
    }

    private function stateOf(Server $server): string
    {
        return (string) Arr::get($this->details($server) ?? [], 'state', 'unknown');
    }

    /**
     * Live state straight from the node (not the panel's 20-second resource cache), so a
     * restart can be followed closely. Null when the node cannot be reached.
     */
    private function details(Server $server): ?array
    {
        try {
            return Daemon::server($server)->details();
        } catch (DaemonConnectionException) {
            return null;
        }
    }

    private function forgetState(Server $server): void
    {
        Cache::forget("panel-mcp:node-states:{$server->node_id}");
        // The panel's own resource cache (ReadsServerState), so the web UI catches up too.
        Cache::forget("resources:{$server->uuid}");
    }

    /**
     * The directory entry for a path, or null when it does not exist.
     */
    private function entry(Server $server, string $path): ?array
    {
        try {
            $entries = $this->directories->list($server, $this->parent($path));
        } catch (DaemonConnectionException) {
            return null;
        }

        foreach ($entries as $e) {
            if (($e['name'] ?? null) === basename($path)) {
                return $e;
            }
        }

        return null;
    }

    private function capOutput(string $text): string
    {
        return strlen($text) <= self::MAX_OUTPUT ? $text : mb_strcut($text, 0, self::MAX_OUTPUT)."\n[cut short]";
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /**
     * Finds a server by short id, UUID or exact name, and applies the same access rules as the
     * panel's client API (AuthenticateServerAccess + the per-request permission).
     */
    private function server(mixed $ref, ?Permissions $permission): Server
    {
        $ref = trim(is_scalar($ref) ? (string) $ref : '');
        if ($ref === '') {
            throw new ToolException('Missing required argument: server');
        }

        $with = ['subusers', 'node', 'transfer'];
        $matches = Server::query()->with($with)->where(fn ($q) => $q->where('uuidShort', $ref)->orWhere('uuid', $ref))->get();
        if ($matches->isEmpty()) {
            $matches = Server::query()->with($with)->whereRaw('LOWER(name) = ?', [mb_strtolower($ref)])->get();
        }

        $matches = $matches->filter(fn (Server $s) => $this->user->root_admin
            || (int) $s->owner_id === (int) $this->user->id
            || $s->subusers->contains('user_id', $this->user->id))->values();

        if ($matches->isEmpty()) {
            throw new ToolException("No server \"{$ref}\" that you can access. Use list_servers and pass its id.");
        }
        if ($matches->count() > 1) {
            throw new ToolException("More than one server is named \"{$ref}\". Pass the id from list_servers instead.");
        }

        /** @var Server $server */
        $server = $matches->first();

        if ($permission !== null) {
            if (! $this->can($permission, $server)) {
                throw new ToolException("Your panel account does not have the \"{$permission->value}\" permission on {$server->name}.");
            }
            if ($reason = $this->blockedReason($server)) {
                throw new ToolException("{$server->name} is {$reason}, so this cannot be done right now.");
            }
        }

        return $server;
    }

    private function can(Permissions $permission, Server $server): bool
    {
        return Gate::forUser($this->user)->allows($permission->value, $server);
    }

    private function permissions(Server $server): array|string
    {
        if ($this->user->root_admin || (int) $server->owner_id === (int) $this->user->id) {
            return 'all';
        }

        return $server->subusers->firstWhere('user_id', $this->user->id)?->permissions ?? [];
    }

    private function blockedReason(Server $server): ?string
    {
        try {
            $server->validateCurrentState();
        } catch (ServerStateConflictException) {
            return match (true) {
                $server->isSuspended() => 'suspended',
                $server->node->isUnderMaintenance() => 'on a node under maintenance',
                ! $server->isInstalled() => 'still installing',
                $server->status === Server::STATUS_RESTORING_BACKUP => 'restoring a backup',
                default => 'being transferred',
            };
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function consoleLines(Server $server, int $lines): array
    {
        return array_map(
            // Strip colour codes and carriage returns.
            fn ($l) => rtrim(preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e[=>]|\r/', '', $l) ?? $l),
            $this->logs->read($server, max(1, min(self::CONSOLE_MAX_LINES, $lines)))
        );
    }

    private function path(mixed $path): string
    {
        if (! is_scalar($path)) {
            throw new ToolException('That path is not valid.');
        }
        $path = (string) $path;
        if (str_contains($path, "\0")) {
            throw new ToolException('That path is not valid.');
        }

        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new ToolException('Paths may not contain "..".');
            }
            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }

    private function parent(string $path): string
    {
        $parent = dirname($path);

        return $parent === '.' || $parent === '' ? '/' : $parent;
    }

    private function exists(Server $server, string $path): bool
    {
        try {
            $entries = $this->directories->list($server, $this->parent($path));
        } catch (DaemonConnectionException) {
            // The folder is not there, so neither is the file.
            return false;
        }

        return in_array(basename($path), array_column($entries, 'name'), true);
    }

    private function maxEditSize(): int
    {
        return (int) config('pterodactyl.files.max_edit_size');
    }

    private function assertWritable(string $content): void
    {
        if (strlen($content) > $this->maxEditSize()) {
            throw new ToolException('That is larger than the panel allows for one file edit.');
        }
    }

    /**
     * @return array{0: string, 1: string[]}
     */
    private function rootAndNames(array $args): array
    {
        $root = $this->path($args['directory'] ?? '/');
        $names = array_values(array_filter((array) $args['names'], fn ($n) => is_string($n) && $n !== ''));
        if ($names === [] || count($names) > 100) {
            throw new ToolException('names must list 1 to 100 files or folders.');
        }
        foreach ($names as &$name) {
            $name = ltrim($this->path($name), '/');
            if ($name === '') {
                throw new ToolException('names may not include the folder itself.');
            }
        }
        unset($name);

        return [$root, $names];
    }

    /**
     * A text argument. Numbers and booleans are accepted as text; anything else is refused.
     */
    private function text(array $args, string $key, string $default = ''): string
    {
        $value = $args[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (! is_scalar($value)) {
            throw new ToolException("{$key} must be a string.");
        }

        return (string) $value;
    }

    private function int(array $args, string $key, int $default): int
    {
        $value = $args[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }
        if ((is_float($value) && floor($value) === $value) || (is_string($value) && preg_match('/^-?\d{1,15}$/', trim($value)))) {
            return (int) $value;
        }

        throw new ToolException("{$key} must be a whole number.");
    }

    private function bool(array $args, string $key): bool
    {
        $value = $args[$key] ?? false;

        return is_string($value) ? in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true) : (bool) $value;
    }

    /**
     * Records the action in the activity log as the user. If the panel's action already wrote an
     * entry with this event during the tool call, the details it lacks (e.g. an upload's sha256)
     * are added to that entry instead of writing a second one. Otherwise a fresh logger writes
     * it, so nothing (subject, actor) carries over between tool calls in a batch. In production
     * the logger never throws; it records its own failures.
     */
    private function log(Server $server, string $event, array $properties = [], Model ...$subjects): void
    {
        foreach ($this->logged as $i => $entry) {
            if ($entry->event !== $event || (int) $entry->actor_id !== (int) $this->user->id) {
                continue;
            }
            unset($this->logged[$i]);
            $missing = array_diff_key($properties, $entry->properties->all());
            if ($missing !== []) {
                $entry->properties = $entry->properties->merge($missing);
                $entry->save();
            }

            return;
        }

        app(ActivityLogService::class)
            ->event($event)
            ->actor($this->user)
            ->subject($server, ...$subjects)
            ->property($properties)
            ->log();
    }

    private function catalog(): array
    {
        $server = ['type' => 'string', 'description' => 'Server id from list_servers (the short id), its UUID, or its exact name.'];
        $read = OAuthService::SCOPE_READ;
        $control = OAuthService::SCOPE_CONTROL;
        $write = OAuthService::SCOPE_WRITE;
        $manage = OAuthService::SCOPE_MANAGE;
        $backup = ['type' => 'string', 'description' => 'Backup id from list_backups, or its exact name.'];
        $allocation = ['type' => 'string', 'description' => 'Port id from list_allocations, or its address (ip:port).'];
        $database = ['type' => 'string', 'description' => 'Database name from list_databases (the s<number>_ prefix may be left out).'];

        $tools = [
            'list_servers' => [
                'scope' => $read, 'handler' => 'listServers', 'title' => 'List servers',
                'description' => 'Lists the servers this account can access, with live state (running, offline, starting, stopping), CPU and memory. Start here to get server ids.',
                'properties' => ['filter' => ['type' => 'string', 'description' => 'Only servers whose name or description contains this text.']],
                'required' => [],
            ],
            'get_server' => [
                'scope' => $read, 'handler' => 'getServer', 'title' => 'Server details',
                'description' => 'Details of one server: state, resource use and limits, addresses, startup command and variables, SFTP location, and what this account may do on it.',
                'properties' => ['server' => $server],
                'required' => ['server'],
            ],
            'get_console' => [
                'scope' => $read, 'handler' => 'getConsole', 'title' => 'Read console',
                'description' => 'The most recent console output of a server (at most 100 lines; that is all the node keeps ready). For older output read the log file instead, e.g. read_file logs/latest.log with tail: true.',
                'properties' => [
                    'server' => $server,
                    'lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'How many lines (default 100).'],
                ],
                'required' => ['server'],
            ],
            'list_files' => [
                'scope' => $read, 'handler' => 'listFiles', 'title' => 'List files',
                'description' => 'Lists a folder on a server. Folder names end with "/". Sizes are in bytes.',
                'properties' => [
                    'server' => $server,
                    'directory' => ['type' => 'string', 'description' => 'Folder path, default "/" (the server root).'],
                ],
                'required' => ['server'],
            ],
            'read_file' => [
                'scope' => $read, 'handler' => 'readFile', 'title' => 'Read file',
                'description' => 'Reads a text file (up to 16 MB) from a server. Returns up to max_lines lines from start_line, or the last lines with tail. Use contains to show only matching lines with their line numbers, which is the quick way to search a log. Config files often hold passwords and keys: do not repeat those back.',
                'properties' => [
                    'server' => $server,
                    'path' => ['type' => 'string', 'description' => 'File path from the server root, e.g. plugins/Essentials/config.yml'],
                    'start_line' => ['type' => 'integer', 'minimum' => 1, 'description' => 'First line to return (default 1).'],
                    'max_lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'description' => 'Most lines to return (default 500).'],
                    'tail' => ['type' => 'boolean', 'description' => 'Return the last max_lines lines instead.'],
                    'contains' => ['type' => 'string', 'description' => 'Only lines containing this text (not case sensitive).'],
                    'line_numbers' => ['type' => 'boolean', 'description' => 'Prefix each line with its number.'],
                ],
                'required' => ['server', 'path'],
            ],
            'list_schedules' => [
                'scope' => $read, 'handler' => 'listSchedules', 'title' => 'List schedules',
                'description' => 'Lists a server\'s schedules with their cron timing and tasks.',
                'properties' => ['server' => $server],
                'required' => ['server'],
            ],

            'get_activity' => [
                'scope' => $read, 'handler' => 'getActivity', 'title' => 'Server activity log',
                'description' => 'The panel\'s activity log for a server, newest first: who started, stopped or restarted it, sent which console command, or changed which file, and when. Actions taken through this connector appear here too.',
                'properties' => [
                    'server' => $server,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Entries to return (default 25).'],
                    'event' => ['type' => 'string', 'description' => 'Only events containing this text, e.g. "power", "console.command", "file.write".'],
                ],
                'required' => ['server'],
            ],
            'search_files' => [
                'scope' => $read, 'handler' => 'searchFiles', 'title' => 'Search files',
                'description' => 'Searches a folder and its subfolders on a server, either for text inside files (default) or for file names. The folder itself is checked first, then each level down (breadth first). A name search matches files and folders and stops once the level with the first match is done (exhaustive: true to keep going). Text search reads config-like files (yml, json, properties, toml, conf, txt and similar) up to 2 MB each and returns path:line: text, and also lists files whose NAME contains the text (so a jar is found even though it is not read). Bounded: 300 folders for text (1500 by name), 250 files, 20 seconds; point it at the narrowest folder and use max_depth. World data, caches and logs folders are skipped. To look inside a jar use inspect_archive.',
                'properties' => [
                    'server' => $server,
                    'query' => ['type' => 'string', 'description' => 'Text to find (not case sensitive).'],
                    'directory' => ['type' => 'string', 'description' => 'Folder to search, default "/".'],
                    'match' => ['type' => 'string', 'enum' => ['content', 'name'], 'description' => 'content (default) or name.'],
                    'name_pattern' => ['type' => 'string', 'description' => 'Only files whose name matches this wildcard, e.g. "*.yml" or "config*".'],
                    'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Default 50.'],
                    'max_depth' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 12, 'description' => 'How many folder levels below directory to go (0 = only directory itself; default 8).'],
                    'exhaustive' => ['type' => 'boolean', 'description' => 'Name search only: keep going through every subfolder after the first match (by default it stops once the folder level with the first match is done).'],
                ],
                'required' => ['server', 'query'],
            ],

            'download_file' => [
                'scope' => $read, 'handler' => 'downloadFile', 'title' => 'Download file (any type)',
                'description' => 'Gets any file, including binaries such as jars. as: "base64" returns the bytes inline (files up to 256 KB); as: "link" returns a download URL valid for 15 minutes (any size; fetch it with curl if you have a shell). The default picks base64 when the file is small enough.',
                'properties' => [
                    'server' => $server,
                    'path' => ['type' => 'string'],
                    'as' => ['type' => 'string', 'enum' => ['base64', 'link']],
                ],
                'required' => ['server', 'path'],
            ],
            'inspect_archive' => [
                'scope' => $read, 'handler' => 'inspectArchive', 'title' => 'Look inside a jar or zip',
                'description' => 'Reads a jar or zip (up to 64 MB) on the server without unpacking it. Without entry: lists the files inside and shows plugin.yml / paper-plugin.yml / mod descriptors. With entry: returns that file; text as text, a .class file as an outline (class, parents, fields, method signatures and every string constant, but not method bodies), other binaries as base64 when small.',
                'properties' => [
                    'server' => $server,
                    'path' => ['type' => 'string', 'description' => 'Path of the jar or zip.'],
                    'entry' => ['type' => 'string', 'description' => 'File inside the archive, e.g. plugin.yml or com/example/Main.class.'],
                    'filter' => ['type' => 'string', 'description' => 'When listing, only names containing this text.'],
                ],
                'required' => ['server', 'path'],
            ],
            'wait_for_console' => [
                'scope' => $read, 'handler' => 'waitForConsole', 'title' => 'Wait for a console line',
                'description' => 'Waits until a NEW line containing text appears in a server\'s console (and logs/latest.log, so fast bursts are not missed), up to timeout_seconds (max 90; call again to keep waiting). Use it to follow a restart: e.g. text "Done (" for a Paper server being ready, or "Enabling MyPlugin". Lines already there when you call are ignored unless include_existing is true. Returns the matching line, or the server state on timeout.',
                'properties' => [
                    'server' => $server,
                    'text' => ['type' => 'string', 'description' => 'Text to wait for (not case sensitive).'],
                    'regex' => ['type' => 'boolean', 'description' => 'Treat text as a regular expression.'],
                    'timeout_seconds' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 90, 'description' => 'Default 60.'],
                    'include_existing' => ['type' => 'boolean', 'description' => 'Also match lines that were already there.'],
                ],
                'required' => ['server', 'text'],
            ],
            'list_backups' => [
                'scope' => $read, 'handler' => 'listBackups', 'title' => 'List backups',
                'description' => 'Lists a server\'s backups, newest first: id, name, whether it finished, failed or is still running, size and lock, and how many the server may keep.',
                'properties' => ['server' => $server],
                'required' => ['server'],
            ],
            'download_backup' => [
                'scope' => $read, 'handler' => 'downloadBackup', 'title' => 'Backup download link', 'requires' => GeneratesBackupDownloadLinks::class,
                'description' => 'A link, valid for a few minutes, to download a finished backup as a .tar.gz. Anyone holding the link can download the backup, so give it only to the user.',
                'properties' => ['server' => $server, 'backup' => $backup],
                'required' => ['server', 'backup'],
            ],
            'list_allocations' => [
                'scope' => $read, 'handler' => 'listAllocations', 'title' => 'List ports',
                'description' => 'Lists a server\'s network ports (allocations): id, address, notes, which one is primary (the address players use), and how many it may have.',
                'properties' => ['server' => $server],
                'required' => ['server'],
            ],
            'list_databases' => [
                'scope' => $read, 'handler' => 'listDatabases', 'title' => 'List databases',
                'description' => 'Lists a server\'s MySQL/MariaDB databases: name, host, username, where connections may come from, and how many the server may have.',
                'properties' => [
                    'server' => $server,
                    'include_passwords' => ['type' => 'boolean', 'description' => 'Also return the passwords (default false). Needs "Manage backups, ports and databases" access and the database.view_password permission. Only ask for them to write into a config.'],
                ],
                'required' => ['server'],
            ],

            'power_action' => [
                'scope' => $control, 'handler' => 'powerAction', 'title' => 'Power action', 'destructive' => true,
                'description' => 'Starts, stops, restarts or kills a server. stop and restart disconnect everyone on it; kill ends the process without saving and can lose data, so use it only when stop has failed. Confirm with the user before stop, restart or kill on a server with players.',
                'properties' => [
                    'server' => $server,
                    'action' => ['type' => 'string', 'enum' => Task::POWER_ACTIONS],
                ],
                'required' => ['server', 'action'],
            ],
            'send_command' => [
                'scope' => $control, 'handler' => 'sendCommand', 'title' => 'Send console command', 'destructive' => true,
                'description' => 'Sends one command to a running server\'s console (no leading slash needed) and returns the console output a moment later. Commands run with full console rights and take effect on live players.',
                'properties' => [
                    'server' => $server,
                    'command' => ['type' => 'string', 'description' => 'The command, one line.'],
                    'wait_seconds' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10, 'description' => 'How long to wait before reading the console back (default 2, 0 to skip).'],
                ],
                'required' => ['server', 'command'],
            ],
            'run_schedule' => [
                'scope' => $control, 'handler' => 'runSchedule', 'title' => 'Run schedule now', 'destructive' => true,
                'description' => 'Runs one of a server\'s schedules immediately. Check its tasks with list_schedules first.',
                'properties' => [
                    'server' => $server,
                    'schedule_id' => ['type' => 'integer', 'description' => 'Schedule id from list_schedules.'],
                ],
                'required' => ['server', 'schedule_id'],
            ],

            'create_backup' => [
                'scope' => $control, 'handler' => 'createBackup', 'title' => 'Create backup', 'requires' => InitiatesBackups::class,
                'description' => 'Starts a backup of a server\'s files. It runs in the background on the node; list_backups shows when it has finished. Refused when the server already keeps as many backups as it may: ask the user before deleting an old one to make room.',
                'properties' => [
                    'server' => $server,
                    'name' => ['type' => 'string', 'description' => 'Default "Backup at <date and time>".'],
                    'locked' => ['type' => 'boolean', 'description' => 'Protect it from deletion until it is unlocked (needs "Manage backups, ports and databases" access and the backup.delete permission). Default false.'],
                    'ignore' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Files or folders to leave out, one pattern each (wildcards work; a leading ! keeps a match). Default: the patterns in the server\'s .pteroignore file, if it has one.'],
                ],
                'required' => ['server'],
            ],

            'edit_file' => [
                'scope' => $write, 'handler' => 'editFile', 'title' => 'Edit file', 'destructive' => true,
                'description' => 'Replaces one exact piece of text in a file with another. Preferred for config changes because the rest of the file is untouched. old_text must match exactly and be unique unless replace_all is set. Most servers only apply config changes after a reload or restart.',
                'properties' => [
                    'server' => $server,
                    'path' => ['type' => 'string'],
                    'old_text' => ['type' => 'string', 'description' => 'Exact text to find.'],
                    'new_text' => ['type' => 'string', 'description' => 'Text to put in its place (empty to remove).'],
                    'replace_all' => ['type' => 'boolean', 'description' => 'Replace every occurrence.'],
                ],
                'required' => ['server', 'path', 'old_text'],
            ],
            'write_file' => [
                'scope' => $write, 'handler' => 'writeFile', 'title' => 'Write file', 'destructive' => true,
                'description' => 'Creates a text file with the given content. Refuses to replace an existing file unless overwrite is true; to change part of a file use edit_file.',
                'properties' => [
                    'server' => $server,
                    'path' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'overwrite' => ['type' => 'boolean', 'description' => 'Replace the file if it already exists.'],
                ],
                'required' => ['server', 'path'],
            ],
            'create_folder' => [
                'scope' => $write, 'handler' => 'createFolder', 'title' => 'Create folder',
                'description' => 'Creates a folder.',
                'properties' => ['server' => $server, 'path' => ['type' => 'string']],
                'required' => ['server', 'path'],
            ],
            'rename_file' => [
                'scope' => $write, 'handler' => 'renameFile', 'title' => 'Move or rename', 'destructive' => true,
                'description' => 'Renames or moves a file or folder. Do not move the jar of a plugin while its server is running.',
                'properties' => [
                    'server' => $server,
                    'from' => ['type' => 'string', 'description' => 'Current path.'],
                    'to' => ['type' => 'string', 'description' => 'New path.'],
                ],
                'required' => ['server', 'from', 'to'],
            ],
            'copy_file' => [
                'scope' => $write, 'handler' => 'copyFile', 'title' => 'Copy file', 'destructive' => true,
                'description' => 'Copies a file. With destination, the copy goes exactly there (any folder, any name); without it, a copy with "copy" in its name is made next to the original (handy as a backup before an edit, but in /plugins that is a second jar that would load on restart). Refuses to replace an existing destination unless overwrite is true.',
                'properties' => [
                    'server' => $server,
                    'path' => ['type' => 'string', 'description' => 'File to copy.'],
                    'destination' => ['type' => 'string', 'description' => 'Full path of the new file, e.g. /backups/MythicTools.jar.'],
                    'overwrite' => ['type' => 'boolean', 'description' => 'Replace the destination if it exists.'],
                ],
                'required' => ['server', 'path'],
            ],
            'delete_files' => [
                'scope' => $write, 'handler' => 'deleteFiles', 'title' => 'Delete files', 'destructive' => true,
                'description' => 'Permanently deletes files or folders (folders with everything inside). There is no recycle bin. Confirm with the user first.',
                'properties' => [
                    'server' => $server,
                    'directory' => ['type' => 'string', 'description' => 'Folder that holds them, default "/".'],
                    'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Names inside that folder.'],
                ],
                'required' => ['server', 'names'],
            ],
            'compress_files' => [
                'scope' => $write, 'handler' => 'compressFiles', 'title' => 'Create archive',
                'description' => 'Packs files or folders into a .tar.gz archive in the same folder. Large folders take a while and use disk space.',
                'properties' => [
                    'server' => $server,
                    'directory' => ['type' => 'string', 'description' => 'Folder that holds them, default "/".'],
                    'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Names inside that folder.'],
                ],
                'required' => ['server', 'names'],
            ],
            'decompress_file' => [
                'scope' => $write, 'handler' => 'decompressFile', 'title' => 'Unpack archive', 'destructive' => true,
                'description' => 'Unpacks an archive into the folder it is in, overwriting files with the same names.',
                'properties' => ['server' => $server, 'path' => ['type' => 'string', 'description' => 'Path of the archive.']],
                'required' => ['server', 'path'],
            ],
            'pull_file' => [
                'scope' => $write, 'handler' => 'pullFile', 'title' => 'Download to server', 'destructive' => true,
                'description' => 'Makes the server download a file from a public http(s) address into a folder, e.g. a plugin jar. Only use sources the user named or clearly trusts.',
                'properties' => [
                    'server' => $server,
                    'url' => ['type' => 'string'],
                    'directory' => ['type' => 'string', 'description' => 'Target folder, default "/".'],
                    'filename' => ['type' => 'string', 'description' => 'Name to save as (default: taken from the address).'],
                ],
                'required' => ['server', 'url'],
            ],
            'upload_file' => [
                'scope' => $write, 'handler' => 'uploadFile', 'title' => 'Upload file (any type)', 'destructive' => true,
                'description' => 'Writes any file, including binaries such as plugin jars, from base64 (up to 4 MB). Returns the size and sha256 so you can check it arrived intact. Refuses to replace an existing file unless overwrite is true. For bigger files use create_upload_link. A new jar in /plugins loads at the next restart.',
                'properties' => [
                    'server' => $server,
                    'path' => ['type' => 'string', 'description' => 'Full path of the file, e.g. /plugins/MyPlugin.jar.'],
                    'content_base64' => ['type' => 'string', 'description' => 'The file bytes, base64 encoded.'],
                    'overwrite' => ['type' => 'boolean'],
                ],
                'required' => ['server', 'path', 'content_base64'],
            ],
            'create_upload_link' => [
                'scope' => $write, 'handler' => 'createUploadLink', 'title' => 'Upload link for big files', 'destructive' => true,
                'description' => 'Returns an upload URL for a folder, valid for 15 minutes, plus the curl command to use it (multipart field "files"). For files too big for upload_file, when you have a shell. Uploading a file with an existing name replaces it. Whoever holds the URL can upload to this server until it expires.',
                'properties' => [
                    'server' => $server,
                    'directory' => ['type' => 'string', 'description' => 'Folder to upload into, default "/".'],
                ],
                'required' => ['server'],
            ],
            'set_startup_variable' => [
                'scope' => $write, 'handler' => 'setStartupVariable', 'title' => 'Set startup variable', 'destructive' => true,
                'description' => 'Changes one of a server\'s startup variables (the editable ones get_server lists, such as the server jar file name). Applies at the next start.',
                'properties' => [
                    'server' => $server,
                    'key' => ['type' => 'string', 'description' => 'Variable name, e.g. SERVER_JARFILE.'],
                    'value' => ['type' => 'string'],
                ],
                'required' => ['server', 'key'],
            ],
            'set_docker_image' => [
                'scope' => $write, 'handler' => 'setDockerImage', 'title' => 'Change Docker image', 'destructive' => true, 'requires' => UpdatesServerDockerImage::class,
                'description' => 'Switches a server to another Docker image its type offers, for example a newer Java version for Minecraft. get_server lists them as docker_image_choices. Applies the next time the server starts; a server whose software needs the old image will not start.',
                'properties' => [
                    'server' => $server,
                    'image' => ['type' => 'string', 'description' => 'A label from docker_image_choices (e.g. "Java 21"), or the image itself.'],
                ],
                'required' => ['server', 'image'],
            ],
            'update_server_config' => [
                'scope' => $write, 'admin' => true, 'handler' => 'updateServerConfig', 'title' => 'Change limits or startup command', 'destructive' => true,
                'description' => 'Root admins only. Changes a server\'s memory, CPU or disk limit, its startup command, or its Docker image, and tells the node. Only the fields you pass change. Limits apply at once; the startup command and image apply at the next start. A mistake in the startup command (for example a mistyped JVM flag) stops the server from booting, so show the user the exact old and new command and get a yes first. Keep the container memory limit comfortably above the JVM heap.',
                'properties' => [
                    'server' => $server,
                    'memory_mb' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Container memory limit in MB (0 = unlimited).'],
                    'cpu_percent' => ['type' => 'integer', 'minimum' => 0, 'description' => 'CPU limit, 100 per core (0 = unlimited).'],
                    'disk_mb' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Disk limit in MB (0 = unlimited).'],
                    'startup_command' => ['type' => 'string', 'description' => 'The full new startup command, one line.'],
                    'docker_image' => ['type' => 'string'],
                ],
                'required' => ['server'],
            ],
            'save_schedule' => [
                'scope' => $write, 'handler' => 'saveSchedule', 'title' => 'Create or edit schedule', 'destructive' => true,
                'description' => 'Creates a schedule, or edits one when schedule_id is given (only the fields you pass change). Timing is cron fields in the panel\'s timezone (UTC unless the guide says otherwise). A new schedule has no tasks until you add them with save_schedule_task.',
                'properties' => [
                    'server' => $server,
                    'schedule_id' => ['type' => 'integer', 'description' => 'Omit to create a new schedule.'],
                    'name' => ['type' => 'string'],
                    'minute' => ['type' => 'string', 'description' => 'Cron minute, e.g. "0" or "*/5".'],
                    'hour' => ['type' => 'string', 'description' => 'Cron hour, e.g. "1" or "*".'],
                    'day_of_month' => ['type' => 'string', 'description' => 'Default "*".'],
                    'month' => ['type' => 'string', 'description' => 'Default "*".'],
                    'day_of_week' => ['type' => 'string', 'description' => 'Default "*".'],
                    'active' => ['type' => 'boolean', 'description' => 'Default true.'],
                    'only_when_online' => ['type' => 'boolean', 'description' => 'Skip runs while the server is offline. Default false.'],
                ],
                'required' => ['server'],
            ],
            'delete_schedule' => [
                'scope' => $write, 'handler' => 'deleteSchedule', 'title' => 'Delete schedule', 'destructive' => true,
                'description' => 'Deletes a schedule and all of its tasks.',
                'properties' => ['server' => $server, 'schedule_id' => ['type' => 'integer']],
                'required' => ['server', 'schedule_id'],
            ],
            'save_schedule_task' => [
                'scope' => $write, 'handler' => 'saveScheduleTask', 'title' => 'Add or edit schedule task', 'destructive' => true,
                'description' => 'Adds a task to the end of a schedule, or edits one when task_id is given. A task is a console command, a power action or a backup, run delay_seconds after the previous step.',
                'properties' => [
                    'server' => $server,
                    'schedule_id' => ['type' => 'integer'],
                    'task_id' => ['type' => 'integer', 'description' => 'Omit to add a new task.'],
                    'action' => ['type' => 'string', 'enum' => ['command', 'power', 'backup']],
                    'payload' => ['type' => 'string', 'description' => 'The command, or start/stop/restart/kill for a power task.'],
                    'delay_seconds' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 900, 'description' => 'Wait after the previous task (default 0).'],
                    'continue_on_failure' => ['type' => 'boolean', 'description' => 'Run later tasks even if this one fails.'],
                ],
                'required' => ['server', 'schedule_id'],
            ],
            'delete_schedule_task' => [
                'scope' => $write, 'handler' => 'deleteScheduleTask', 'title' => 'Delete schedule task', 'destructive' => true,
                'description' => 'Removes one task from a schedule.',
                'properties' => ['server' => $server, 'schedule_id' => ['type' => 'integer'], 'task_id' => ['type' => 'integer']],
                'required' => ['server', 'schedule_id', 'task_id'],
            ],

            'restore_backup' => [
                'scope' => $manage, 'handler' => 'restoreBackup', 'title' => 'Restore backup', 'destructive' => true, 'requires' => RestoresBackups::class,
                'description' => 'Restores a finished backup. The node stops the server first (everyone on it is disconnected) and leaves it stopped. By default the backup is unpacked over the current files, so files made since the backup stay; delete_existing_files deletes every current file first. Get the user\'s yes first, and offer a backup of the current state before restoring.',
                'properties' => [
                    'server' => $server,
                    'backup' => $backup,
                    'delete_existing_files' => ['type' => 'boolean', 'description' => 'Delete all of the server\'s files before restoring (default false).'],
                ],
                'required' => ['server', 'backup'],
            ],
            'delete_backup' => [
                'scope' => $manage, 'handler' => 'deleteBackup', 'title' => 'Delete backup', 'destructive' => true, 'requires' => DeletesBackups::class,
                'description' => 'Deletes a backup for good. A locked backup must be unlocked with set_backup_lock first.',
                'properties' => ['server' => $server, 'backup' => $backup],
                'required' => ['server', 'backup'],
            ],
            'set_backup_lock' => [
                'scope' => $manage, 'handler' => 'setBackupLock', 'title' => 'Lock or unlock backup', 'destructive' => true, 'requires' => TogglesBackupLocks::class,
                'description' => 'Locks a backup so it cannot be deleted, or unlocks it.',
                'properties' => ['server' => $server, 'backup' => $backup, 'locked' => ['type' => 'boolean']],
                'required' => ['server', 'backup', 'locked'],
            ],
            'add_allocation' => [
                'scope' => $manage, 'handler' => 'addAllocation', 'title' => 'Add port', 'requires' => AssignsAllocations::class,
                'description' => 'Gives a server one more port, picked by the panel from the free ports on its node (you cannot choose the number). Refused when the server has as many ports as it may, or the panel does not hand out ports automatically. The node opens it at the next start.',
                'properties' => [
                    'server' => $server,
                    'notes' => ['type' => 'string', 'description' => 'What the port is for, e.g. "Geyser" (at most 256 characters).'],
                ],
                'required' => ['server'],
            ],
            'update_allocation' => [
                'scope' => $manage, 'handler' => 'updateAllocation', 'title' => 'Change port', 'destructive' => true, 'requires' => SetsPrimaryAllocations::class,
                'description' => 'Sets a port\'s notes, or makes it the primary port (the address players connect to, from the next start).',
                'properties' => [
                    'server' => $server,
                    'allocation' => $allocation,
                    'notes' => ['type' => 'string', 'description' => 'New notes; an empty string clears them.'],
                    'primary' => ['type' => 'boolean', 'description' => 'true makes this the primary port.'],
                ],
                'required' => ['server', 'allocation'],
            ],
            'remove_allocation' => [
                'scope' => $manage, 'handler' => 'removeAllocation', 'title' => 'Remove port', 'destructive' => true, 'requires' => UnassignsAllocations::class,
                'description' => 'Takes a port away from a server. The primary port cannot be removed.',
                'properties' => ['server' => $server, 'allocation' => $allocation],
                'required' => ['server', 'allocation'],
            ],
            'create_database' => [
                'scope' => $manage, 'handler' => 'createDatabase', 'title' => 'Create database', 'requires' => DeploysServerDatabases::class,
                'description' => 'Creates a MySQL/MariaDB database and user for a server (for plugins such as LuckPerms) and returns the host, database name, username and password to put in the plugin\'s config. The panel adds an s<number>_ prefix to the name.',
                'properties' => [
                    'server' => $server,
                    'name' => ['type' => 'string', 'description' => '3 to 48 letters, digits, dashes or underscores, e.g. "luckperms".'],
                    'connections_from' => ['type' => 'string', 'description' => 'Where the user may connect from: "%" for anywhere (default), or an IP address.'],
                ],
                'required' => ['server', 'name'],
            ],
            'rotate_database_password' => [
                'scope' => $manage, 'handler' => 'rotateDatabasePassword', 'title' => 'New database password', 'destructive' => true, 'requires' => RotatesDatabasePasswords::class,
                'description' => 'Gives a database user a new random password and returns it. Anything still using the old password stops connecting until its config is updated.',
                'properties' => ['server' => $server, 'database' => $database],
                'required' => ['server', 'database'],
            ],
            'delete_database' => [
                'scope' => $manage, 'handler' => 'deleteDatabase', 'title' => 'Delete database', 'destructive' => true, 'requires' => DeletesDatabases::class,
                'description' => 'Deletes a database and its user with all of the data in it. This cannot be undone.',
                'properties' => ['server' => $server, 'database' => $database],
                'required' => ['server', 'database'],
            ],
            'rename_server' => [
                'scope' => $manage, 'handler' => 'renameServer', 'title' => 'Rename server', 'destructive' => true, 'requires' => UpdatesServerDetails::class,
                'description' => 'Changes a server\'s name or description as shown in the panel. Only the fields you pass change.',
                'properties' => [
                    'server' => $server,
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string', 'description' => 'An empty string clears it.'],
                ],
                'required' => ['server'],
            ],
            'reinstall_server' => [
                'scope' => $manage, 'handler' => 'reinstallServer', 'title' => 'Reinstall server', 'destructive' => true, 'requires' => ReinstallsServers::class,
                'description' => 'Stops a server and runs its install script again, as when it was created. The script can delete or replace files (often the server jar), so get the user\'s yes and offer a backup first.',
                'properties' => ['server' => $server],
                'required' => ['server'],
            ],
        ];

        // A tool whose panel action this build lacks (an older 2.0 release) is left out.
        $tools = array_filter($tools, fn (array $t) => ! isset($t['requires']) || interface_exists($t['requires']));

        return $tools + $this->admin->catalog() + $this->addedTools();
    }

    /**
     * Tools other extensions added through the ProvidesTools tag. A malformed tool is left out and
     * logged once per request; it never breaks the built-in tools.
     */
    private function addedTools(): array
    {
        if ($this->added !== null) {
            return $this->added;
        }

        $this->added = [];
        foreach (app()->tagged(ProvidesTools::TAG) as $provider) {
            if (! $provider instanceof ProvidesTools) {
                Log::warning('Panel MCP: a tagged tool provider does not implement ProvidesTools', ['class' => get_debug_type($provider)]);

                continue;
            }
            foreach ($provider->tools() as $name => $tool) {
                $valid = is_string($name) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) === 1
                    && is_array($tool)
                    && isset(OAuthService::SCOPES[$tool['scope'] ?? null])
                    && is_string($tool['title'] ?? null) && is_string($tool['description'] ?? null)
                    && is_array($tool['properties'] ?? null) && is_array($tool['required'] ?? null)
                    && is_callable($tool['run'] ?? null);
                if (! $valid) {
                    Log::warning('Panel MCP: left out a malformed tool', ['tool' => $name, 'provider' => get_class($provider)]);

                    continue;
                }
                unset($tool['handler']);
                $this->added[$name] ??= $tool;
            }
        }

        return $this->added;
    }
}
