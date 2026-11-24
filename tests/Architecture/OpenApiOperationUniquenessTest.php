<?php

declare(strict_types=1);

namespace Tests\Architecture;

/**
 * swagger-php refuses to build the API documentation when two attributes declare the same HTTP method and path
 * ("Unable to merge @OA\Get()"), which stops the Swagger step of the CI for every pull request. Catch it here, with
 * the two places named.
 */
test('every OpenAPI operation is declared once', function (): void {
    $seen = [];
    $root = dirname(__DIR__, 2);

    foreach (['app', 'modules', 'packages'] as $directory) {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (! str_contains($source, 'OA\\')) {
                continue;
            }

            $offset = 0;
            while (preg_match('/OA\\\\(Get|Post|Put|Patch|Delete)\(/', $source, $method, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset = $method[0][1] + 1;
                if (preg_match('/\bpath:\s*[\'"]([^\'"]+)[\'"]/', substr($source, $method[0][1], 700), $path) === 1) {
                    $seen[strtoupper($method[1][0]).' '.$path[1]][] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }
    }

    $duplicates = array_filter($seen, static fn (array $files): bool => count($files) > 1);

    expect(array_map(static fn (array $files): string => implode(', ', $files), $duplicates))->toBe([]);
});
