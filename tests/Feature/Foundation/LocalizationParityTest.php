<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

test('verifies filesystem localization asset parity for Italian, German and French across core modules', function (): void {
    $locales = ['it', 'de', 'fr'];
    $domains = ['sales', 'purchases', 'inventory', 'accounting', 'banking', 'projects', 'workflow'];

    foreach ($locales as $locale) {
        $localeDir = base_path("lang/{$locale}");
        expect(is_dir($localeDir))->toBeTrue("Locale directory [{$locale}] must exist.");

        foreach ($domains as $domain) {
            $filePath = "{$localeDir}/{$domain}.php";
            expect(file_exists($filePath))->toBeTrue("Translation file [{$domain}.php] for locale [{$locale}] must exist.");

            $translations = require $filePath;
            expect(is_array($translations))->toBeTrue();
            expect(empty($translations))->toBeFalse("Translation file [{$domain}.php] for [{$locale}] must not be empty.");
            expect(isset($translations['messages']))->toBeTrue("Translation file [{$domain}.php] for [{$locale}] must contain messages key.");
        }
    }
});
