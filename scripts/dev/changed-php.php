<?php

declare(strict_types=1);

/**
 * Lists the PHP files changed in the working tree (staged, unstaged and untracked) that PHPStan should analyse.
 *
 * Usage: php scripts/dev/changed-php.php [--staged]
 *
 * Prints one path per line; prints nothing when no analysable file changed. Cross-platform (no shell utilities needed
 * beyond git), so it works from PowerShell, Git Bash, Linux and macOS alike.
 */
$staged = in_array('--staged', $argv, true);

$commands = $staged
    ? ['git diff --cached --name-only --diff-filter=ACMR']
    : [
        'git diff --name-only --diff-filter=ACMR HEAD',
        'git ls-files --others --exclude-standard',
    ];

$files = [];

foreach ($commands as $command) {
    $output = [];
    exec($command, $output);

    foreach ($output as $line) {
        $files[trim($line)] = true;
    }
}

// Mirrors the `paths` / `excludePaths` of phpstan.neon: tests are not part of the static analysis scope.
$analysed = '#^(app|modules|packages|routes|config|database/factories|database/seeders)/#';
$excluded = '#^(modules|packages)/[^/]+/tests/#';

foreach (array_keys($files) as $file) {
    if (str_ends_with($file, '.php')
        && preg_match($analysed, $file) === 1
        && preg_match($excluded, $file) !== 1
        && is_file($file)) {
        echo $file, PHP_EOL;
    }
}
