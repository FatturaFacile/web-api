<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the static translation keys a frontend source tree uses: the `'KEY' | translate` pipe in templates and the
 * `translate('KEY')`, `instant('KEY')`, `translateIn(lang, 'KEY')` and `t('KEY')` calls in TypeScript. Keys built at run
 * time (template literals, concatenation) cannot be known statically and are skipped.
 */
final class FrontendKeyScanner
{
    private const string KEY = '[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)+';

    private const array EXTENSIONS = ['html', 'ts'];

    /**
     * @return array<string, string> key => first file that uses it (relative to the scanned root)
     */
    public function scan(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $found = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! in_array(strtolower($file->getExtension()), self::EXTENSIONS, true) || $this->isIgnored($path)) {
                continue;
            }

            foreach ($this->keysIn((string) file_get_contents($path)) as $key) {
                $found[$key] ??= ltrim(substr($path, strlen($root)), '/');
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    public function keysIn(string $source): array
    {
        // A literal followed by "+" is the prefix of a key built at run time (translate('KIND_' + kind)), not a key
        $patterns = [
            '/[\'"`]('.self::KEY.')[\'"`]\s*\|\s*translate\b/',
            '/\b(?:translate|instant|t)\(\s*[\'"`]('.self::KEY.')[\'"`](?!\s*\+)/',
            '/\btranslateIn\(\s*[^,()]+,\s*[\'"`]('.self::KEY.')[\'"`](?!\s*\+)/',
        ];

        $keys = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    if (! str_ends_with($key, '_') && ! str_ends_with($key, '-')) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        return array_map('strval', array_keys($keys));
    }

    private function isIgnored(string $path): bool
    {
        return str_contains($path, '/node_modules/')
            || str_contains($path, '/dist/')
            || str_contains($path, '/.angular/')
            || str_ends_with($path, '.spec.ts')
            || str_ends_with($path, '.d.ts');
    }
}
