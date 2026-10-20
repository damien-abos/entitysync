<?php

/**
 * Entity Sync plugin for GLPI
 *
 * Minimal, dependency-free test runner for the engine (which does not depend on GLPI).
 * Usage: php tests/run.php [filter]
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'GlpiPlugin\\Entitysync\\Tests\\' => __DIR__ . '/',
        'GlpiPlugin\\Entitysync\\'        => dirname(__DIR__) . '/src/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

$filter = $argv[1] ?? '';
$failures = 0;
$passed = 0;

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/Engine'));
$files = [];
foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}
sort($files);

foreach ($files as $path) {
    $relative = substr($path, strlen(__DIR__) + 1, -4);
    $class = 'GlpiPlugin\\Entitysync\\Tests\\' . str_replace('/', '\\', $relative);
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $name = $class . '::' . $method;
        if ($filter !== '' && !str_contains($name, $filter)) {
            continue;
        }
        try {
            (new $class())->$method();
            $passed++;
        } catch (Throwable $e) {
            $failures++;
            fwrite(STDERR, "FAIL {$name}\n  " . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n");
        }
    }
}

echo sprintf("%d passed, %d failed\n", $passed, $failures);
exit($failures === 0 ? 0 : 1);
