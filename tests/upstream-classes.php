<?php

// Checks that every Pterodactyl\ class, interface or enum this extension names still exists in a
// panel checkout, so an upstream rename or removal fails CI instead of a live MCP call.
//   php tests/upstream-classes.php /path/to/panel
// Exit code 1 lists what is missing.

// Old names kept on purpose as fallbacks for older panel builds (see McpTools::nodeToken()).
const FALLBACKS = [
    'Pterodactyl\\Services\\Nodes\\NodeJWTService',
];

$panel = rtrim($argv[1] ?? '', '/');
if ($panel === '' || ! is_dir("{$panel}/app")) {
    fwrite(STDERR, "usage: php tests/upstream-classes.php <panel checkout>\n");
    exit(2);
}

$root = dirname(__DIR__);
$names = [];
foreach (['src', 'routes', 'database', 'resources'] as $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        // "use Pterodactyl\X\Y;", "\Pterodactyl\X\Y::class" and 'Pterodactyl\\X\\Y' in strings.
        preg_match_all('/Pterodactyl(?:\\\\{1,2}[A-Za-z_][A-Za-z0-9_]*)+/', (string) file_get_contents($file->getPathname()), $m);
        foreach ($m[0] as $name) {
            $names[(string) preg_replace('/\\\\+/', '\\', $name)][] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}

$missing = [];
foreach ($names as $name => $where) {
    $path = "{$panel}/app/".str_replace('\\', '/', substr($name, strlen('Pterodactyl\\')));
    // A namespace prefix (e.g. in a comment) is a folder, not a file.
    if (is_file("{$path}.php") || is_dir($path) || in_array($name, FALLBACKS, true)) {
        continue;
    }
    $missing[] = "{$name}  (".implode(', ', array_unique($where)).')';
}

echo count($names)." Pterodactyl names checked against {$panel}\n";
if ($missing !== []) {
    echo "Missing upstream:\n  ".implode("\n  ", $missing)."\n";
    exit(1);
}
