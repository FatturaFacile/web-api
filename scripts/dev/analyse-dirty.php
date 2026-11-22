<?php

declare(strict_types=1);

/**
 * Runs PHPStan only on the changed PHP files (see changed-php.php), reusing the warm result cache.
 *
 * Usage: php scripts/dev/analyse-dirty.php [--staged]
 *
 * Exits 0 without starting PHPStan when nothing analysable changed. Cross-platform.
 */
$staged = in_array('--staged', $argv, true) ? ' --staged' : '';

$files = [];
exec('"'.PHP_BINARY.'" '.escapeshellarg(__DIR__.'/changed-php.php').$staged, $files);
$files = array_values(array_filter($files, static fn (string $file): bool => $file !== ''));

if ($files === []) {
    fwrite(STDOUT, "PHPStan: no changed PHP files in scope, skipped.\n");
    exit(0);
}

$command = escapeshellarg(PHP_BINARY).' vendor/bin/phpstan analyse --memory-limit=2G --no-progress '
    .implode(' ', array_map('escapeshellarg', $files));

passthru($command, $exitCode);
exit($exitCode);
