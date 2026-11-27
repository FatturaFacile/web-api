<?php

declare(strict_types=1);

$fix = in_array('--fix', $argv, true);
$root = dirname(__DIR__);
$excludeDirs = ['.git', '.claude', 'vendor', 'node_modules', 'storage', 'bootstrap'];
$extensions = ['php', 'md', 'json', 'yaml', 'yml', 'neon', 'xml', 'stub'];

$dir = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator($dir, function (SplFileInfo $current) use ($excludeDirs): bool {
    if ($current->isDir()) {
        return ! in_array($current->getFilename(), $excludeDirs, true);
    }

    return true;
});

$iterator = new RecursiveIteratorIterator($filter);
$errors = [];
$fixed = [];

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if (! $file->isFile()) {
        continue;
    }

    $ext = strtolower($file->getExtension());
    $filename = $file->getFilename();

    if (! in_array($ext, $extensions, true) && ! str_starts_with($filename, '.env')) {
        continue;
    }

    $path = $file->getPathname();
    $relativePath = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR.'/\\');
    $content = file_get_contents($path);

    if ($content === false) {
        $errors[] = "[UNREADABLE] {$relativePath}";

        continue;
    }

    $hasBom = str_starts_with($content, "\xEF\xBB\xBF");
    $isValidUtf8 = mb_check_encoding($content, 'UTF-8');

    if (! $hasBom && $isValidUtf8) {
        continue;
    }

    if (! $fix) {
        if ($hasBom) {
            $errors[] = "[UTF-8 BOM]  {$relativePath}";
        }
        if (! $isValidUtf8) {
            $errors[] = "[NON-UTF8]   {$relativePath}";
        }

        continue;
    }

    $updated = $content;
    $actions = [];

    if ($hasBom) {
        $updated = substr($updated, 3);
        $actions[] = 'stripped UTF-8 BOM';
    }

    if (! mb_check_encoding($updated, 'UTF-8')) {
        $converted = mb_convert_encoding($updated, 'UTF-8', 'Windows-1252');
        if ($converted === false || ! mb_check_encoding($converted, 'UTF-8')) {
            $errors[] = "[FIX FAILED] {$relativePath}";

            continue;
        }
        $updated = $converted;
        $actions[] = 'converted Windows-1252 to UTF-8';
    }

    if (file_put_contents($path, $updated) === false) {
        $errors[] = "[WRITE FAIL] {$relativePath}";

        continue;
    }

    $fixed[] = "{$relativePath} (".implode(', ', $actions).')';
}

if ($fixed !== []) {
    echo 'Fixed '.count($fixed)." file(s):\n";
    foreach ($fixed as $item) {
        echo "  - {$item}\n";
    }
}

if ($errors !== []) {
    fwrite(STDERR, 'Found '.count($errors)." file(s) with invalid encoding:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

echo "OK: All scanned files are valid UTF-8 without BOM.\n";
exit(0);
