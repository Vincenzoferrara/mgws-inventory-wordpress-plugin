<?php

/**
 * Dependency-free test runner for the MGWS plugin.
 *
 * Why not phpunit: this environment has no composer and no phpunit, and adding
 * a dependency to run four files of assertions would be a bigger commitment
 * than the tests themselves. The contract is small — a test file calls
 * `mgws_test()` and asserts — and this runner only has to load, run, count and
 * exit non-zero on failure, which is what CI needs.
 *
 * Usage: php tests/run.php [filter]
 * The optional filter runs only the test files whose name contains it.
 */

require_once __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/test_*.php');
sort($files);

if ($filter !== '') {
    $files = array_values(array_filter($files, static function ($file) use ($filter) {
        return strpos(basename($file), $filter) !== false;
    }));
}

if ($files === array()) {
    fwrite(STDERR, "No test files matched.\n");
    exit(2);
}

$started = microtime(true);
foreach ($files as $file) {
    fwrite(STDOUT, basename($file) . "\n");
    require_once $file;
}

$elapsed = microtime(true) - $started;
$passed = $GLOBALS['mgws_test']['passed'];
$failed = $GLOBALS['mgws_test']['failed'];

fwrite(STDOUT, sprintf("\n%d passed, %d failed in %.2fs\n", $passed, $failed, $elapsed));

if ($failed > 0) {
    fwrite(STDOUT, "\nFailures:\n");
    foreach ($GLOBALS['mgws_test']['failures'] as $failure) {
        fwrite(STDOUT, '  - ' . $failure['name'] . ': ' . $failure['message'] . "\n");
    }
    exit(1);
}

exit(0);
