<?php

// Seeds the MCP end-to-end test fixtures into a LOCAL v2 dev instance. Never run against production.
//
//   cd <instance> && php -d error_reporting=0 artisan tinker --execute="require '<repo>/tests/mcp/seed.php';"
//
// Idempotent: everything an earlier run created (node "mcp-test-node", egg "MCP Test Egg", database
// host "mcp-test-dbhost" with the databases made on it, users mcpadmin/mcpstaff/mcptotp and their
// OAuth connections) is removed first. Prints one line of JSON
// (prefixed with SEED_JSON=) that run.sh stores in tests/mcp/.state.json for e2e.py and fakewings.py.
//
// The passwords and the TOTP secret below are test values for this local fixture only.

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\Task;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Databases\DatabaseHostGateway;
use Ramsey\Uuid\Uuid;

$fixture = [
    'admin' => ['username' => 'mcpadmin', 'password' => 'McpAdmin-test-12345'],
    'staff' => ['username' => 'mcpstaff', 'password' => 'McpStaff-test-12345'],
    // Root admin with TOTP turned on, for the login checkpoint test.
    'totp' => ['username' => 'mcptotp', 'password' => 'McpTotp-test-12345', 'secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'],
];

$mk = function (string $class, array $attributes) {
    $model = new $class;
    $model->forceFill($attributes);
    $model->saveQuietly();

    return $model->fresh() ?? $model;
};

// Databases an earlier run created on the test database host exist on MariaDB itself, so they are
// dropped there before their rows go (only the ones the panel recorded for the test servers).
$old = DB::table('nodes')->where('name', 'mcp-test-node')->first();
foreach ($old ? DB::table('databases')->whereIn('server_id', DB::table('servers')->where('node_id', $old->id)->pluck('id'))->get() : [] as $row) {
    try {
        app(DynamicDatabaseConnection::class)->set('dynamic', (int) $row->database_host_id);
        app(DatabaseHostGateway::class)->dropDatabase($row->database);
        app(DatabaseHostGateway::class)->dropUser($row->username, $row->remote);
    } catch (\Throwable) {
    }
}

DB::transaction(function () {
    $node = DB::table('nodes')->where('name', 'mcp-test-node')->first();
    if ($node) {
        $servers = DB::table('servers')->where('node_id', $node->id)->pluck('id');
        DB::table('databases')->whereIn('server_id', $servers)->delete();
        DB::table('backups')->whereIn('server_id', $servers)->delete();
        DB::table('database_hosts')->where('name', 'mcp-test-dbhost')->delete();
        $schedules = DB::table('schedules')->whereIn('server_id', $servers)->pluck('id');
        DB::table('tasks')->whereIn('schedule_id', $schedules)->delete();
        DB::table('schedules')->whereIn('id', $schedules)->delete();
        DB::table('subusers')->whereIn('server_id', $servers)->delete();
        DB::table('server_variables')->whereIn('server_id', $servers)->delete();
        DB::table('allocations')->where('node_id', $node->id)->update(['server_id' => null]);
        DB::table('servers')->whereIn('id', $servers)->delete();
        DB::table('allocations')->where('node_id', $node->id)->delete();
        DB::table('nodes')->where('id', $node->id)->delete();
    }
    DB::table('locations')->where('short', 'mcp-test')->delete();
    $eggs = DB::table('eggs')->where('name', 'MCP Test Egg')->pluck('id');
    DB::table('egg_variables')->whereIn('egg_id', $eggs)->delete();
    DB::table('eggs')->whereIn('id', $eggs)->delete();
    // OAuth grants, codes and tokens go with the users (foreign keys cascade).
    DB::table('users')->whereIn('username', ['mcpadmin', 'mcpstaff', 'mcptotp'])->delete();
    DB::table(\PanelMcp\Tables::clients())->whereNotExists(fn ($q) => $q->selectRaw('1')->from(\PanelMcp\Tables::grants())
        ->whereColumn(\PanelMcp\Tables::grants().'.client_id', \PanelMcp\Tables::clients().'.id'))->delete();
});

$user = fn (string $username, bool $admin, string $password, ?string $totp = null) => $mk(User::class, [
    'uuid' => Uuid::uuid4()->toString(),
    'username' => $username,
    'email' => "{$username}@example.test",
    'name_first' => $username,
    'name_last' => 'Test',
    'language' => 'en',
    'root_admin' => $admin,
    'use_totp' => $totp !== null,
    'totp_secret' => $totp !== null ? Crypt::encrypt($totp) : null,
    'password' => Hash::make($password),
]);

$admin = $user($fixture['admin']['username'], true, $fixture['admin']['password']);
$staff = $user($fixture['staff']['username'], false, $fixture['staff']['password']);
$totp = $user($fixture['totp']['username'], true, $fixture['totp']['password'], $fixture['totp']['secret']);

$location = $mk(Location::class, ['short' => 'mcp-test', 'long' => 'MCP test location']);
$node = $mk(Node::class, [
    'uuid' => Uuid::uuid4()->toString(), 'public' => true, 'name' => 'mcp-test-node', 'location_id' => $location->id,
    'fqdn' => '127.0.0.1', 'scheme' => 'http', 'behind_proxy' => false, 'maintenance_mode' => false,
    'memory' => 1024, 'memory_overallocate' => 0, 'disk' => 10240, 'disk_overallocate' => 0, 'upload_size' => 100,
    'daemon_token_id' => Str::random(16), 'daemon_token' => Crypt::encrypt(Str::random(64)),
    'daemonListen' => 8081, 'daemonSFTP' => 2022, 'daemonBase' => '/var/lib/pterodactyl/volumes',
]);

$egg = $mk(Egg::class, [
    'uuid' => Uuid::uuid4()->toString(), 'nest_id' => null, 'author' => 'mcp@example.test', 'name' => 'MCP Test Egg',
    'description' => 'Fixture for the MCP end-to-end test.', 'docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21', 'Java 25' => 'ghcr.io/pterodactyl/yolks:java_25'],
    'startup' => 'java -jar {{SERVER_JARFILE}}', 'config_files' => '{}', 'config_startup' => '{"done":"Done ("}',
    'config_logs' => '{}', 'config_stop' => 'stop', 'script_container' => 'alpine:3.4', 'script_entry' => 'ash',
    'script_is_privileged' => true, 'script_install' => '',
]);
$jarVariable = $mk(EggVariable::class, [
    'egg_id' => $egg->id, 'name' => 'Server jar file', 'description' => 'The jar to run.', 'env_variable' => 'SERVER_JARFILE',
    'default_value' => 'server.jar', 'user_viewable' => true, 'user_editable' => true,
    'rules' => 'required|regex:/^([\w\d._-]+)(\.jar)$/', 'sort_order' => 1,
]);
$mk(EggVariable::class, [
    'egg_id' => $egg->id, 'name' => 'Version', 'description' => 'Read-only for users.', 'env_variable' => 'MC_VERSION',
    'default_value' => '1.21.11', 'user_viewable' => true, 'user_editable' => false, 'rules' => 'required|string|max:20', 'sort_order' => 2,
]);
$mk(EggVariable::class, [
    'egg_id' => $egg->id, 'name' => 'Hidden', 'description' => 'Not shown to users.', 'env_variable' => 'HIDDEN_SECRET',
    'default_value' => 'hidden', 'user_viewable' => false, 'user_editable' => false, 'rules' => 'nullable|string', 'sort_order' => 3,
]);

$servers = [];
// Hub-01 may keep backups, make databases and hold ports; the other two have none of these (0 / null).
foreach ([['SMP', 19194, []], ['Hub-01', 25566, ['backup_limit' => 3, 'database_limit' => 3, 'allocation_limit' => 3]], ['Secret', 25999, []]] as [$name, $port, $limits]) {
    $allocation = $mk(Allocation::class, ['node_id' => $node->id, 'ip' => '10.0.0.1', 'port' => $port]);
    $server = $mk(Server::class, $limits + [
        'uuid' => Uuid::uuid4()->toString(), 'uuidShort' => Str::lower(Str::random(8)), 'name' => $name, 'description' => '',
        'skip_scripts' => false, 'status' => null, 'memory' => 512, 'swap' => 0, 'disk' => 512, 'io' => 500, 'cpu' => 0,
        'oom_disabled' => false, 'startup' => 'java -jar server.jar', 'image' => 'ghcr.io/pterodactyl/yolks:java_25', 'backup_limit' => 0,
        'owner_id' => $admin->id, 'node_id' => $node->id, 'allocation_id' => $allocation->id, 'egg_id' => $egg->id,
    ]);
    $server->forceFill(['installed_at' => now()])->saveQuietly();
    $allocation->forceFill(['server_id' => $server->id])->saveQuietly();
    $mk(ServerVariable::class, ['server_id' => $server->id, 'variable_id' => $jarVariable->id, 'variable_value' => 'server.jar']);
    $servers[$name] = ['uuid' => $server->uuid, 'id' => $server->uuidShort, 'pk' => $server->id];

    if ($name === 'Hub-01') {
        $mk(Subuser::class, ['user_id' => $staff->id, 'server_id' => $server->id, 'permissions' => ['websocket.connect', 'file.read', 'file.read-content', 'control.console']]);
        // A finished backup to restore, download and lock, and a failed one. Both are old enough not to
        // count towards the panel's "two backups per ten minutes" throttle.
        foreach ([['Nightly', true, 2, 5 * 1048576], ['Failed run', false, 3, 0]] as [$backup, $successful, $hoursAgo, $bytes]) {
            $mk(Backup::class, [
                'server_id' => $server->id, 'uuid' => Uuid::uuid4()->toString(), 'name' => $backup, 'ignored_files' => [], 'disk' => Backup::ADAPTER_WINGS,
                'is_successful' => $successful, 'is_locked' => false, 'checksum' => $successful ? 'sha256:'.hash('sha256', $backup) : null, 'bytes' => $bytes,
                'completed_at' => now()->subHours($hoursAgo), 'created_at' => now()->subHours($hoursAgo),
            ]);
        }
    }
    if ($name === 'SMP') {
        $schedule = $mk(Schedule::class, [
            'server_id' => $server->id, 'name' => 'nightly', 'cron_day_of_week' => '*', 'cron_month' => '*',
            'cron_day_of_month' => '*', 'cron_hour' => '1', 'cron_minute' => '0', 'is_active' => true,
            'is_processing' => false, 'only_when_online' => false,
        ]);
        $mk(Task::class, ['schedule_id' => $schedule->id, 'action' => 'command', 'payload' => 'say hi', 'sequence_id' => 1, 'time_offset' => 0, 'is_queued' => false, 'continue_on_failure' => false]);
    }
}

// Free ports on the node for add_allocation to hand out (the panel picks one at random), a database host that
// is the test MariaDB itself (account mcp_dbhost from fixtures/sqldb.sql), and the panel setting that lets
// users add ports. The setting stays on afterwards.
foreach ([19301, 19302, 19303] as $port) {
    $mk(Allocation::class, ['node_id' => $node->id, 'ip' => '10.0.0.1', 'port' => $port]);
}
$dbHost = $mk(DatabaseHost::class, [
    'name' => 'mcp-test-dbhost', 'host' => '127.0.0.1', 'port' => (int) (getenv('MCP_SQL_PORT') ?: 3306), 'username' => 'mcp_dbhost',
    'password' => Crypt::encrypt('mcp-dbhost-test-only'), 'node_id' => $node->id,
]);
DB::table('settings')->updateOrInsert(['key' => 'settings::pterodactyl:client_features:allocations:enabled'], ['value' => 'true']);

echo 'SEED_JSON='.json_encode([
    'users' => [
        'admin' => $fixture['admin'] + ['id' => $admin->id],
        'staff' => $fixture['staff'] + ['id' => $staff->id],
        'totp' => $fixture['totp'] + ['id' => $totp->id],
    ],
    'servers' => $servers,
    'node_id' => $node->id,
    'database_host' => ['host' => $dbHost->host, 'port' => $dbHost->port],
], JSON_UNESCAPED_SLASHES)."\n";
