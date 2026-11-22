<?php

declare(strict_types=1);

/**
 * Runs a command under the machine-wide local test lock (Windows) or directly (Linux/macOS CI).
 *
 * Usage: php scripts/dev/locked.php <command> [args...]
 */
$arguments = array_slice($argv, 1);

if ($arguments === []) {
    fwrite(STDERR, "Usage: php scripts/dev/locked.php <command> [args...]\n");
    exit(2);
}

$ci = filter_var(getenv('CI') ?: false, FILTER_VALIDATE_BOOL);

if (PHP_OS_FAMILY === 'Windows' && ! $ci) {
    $arguments = [
        'powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', __DIR__.'/../test-lock.ps1',
        ...$arguments,
    ];
}

$command = implode(' ', array_map(escapeshellarg(...), $arguments));

passthru($command, $exitCode);

exit($exitCode);
