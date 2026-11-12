<?php

declare(strict_types=1);

/**
 * The API texts (lang/<locale>/*.php) exist in all the languages: same files, same keys, same :placeholders.
 */
const PARITY_LOCALES = ['it', 'en', 'de', 'fr'];

/**
 * @param  array<string, mixed>  $lines
 * @return array<string, string>
 */
function flattenLangLines(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        if (is_array($value)) {
            $flat += flattenLangLines($value, "{$prefix}{$key}.");

            continue;
        }

        $flat["{$prefix}{$key}"] = (string) $value;
    }

    return $flat;
}

/**
 * @return array<string, array<string, array<string, string>>> file => locale => key => text
 */
function langDictionaries(): array
{
    $files = [];

    foreach (PARITY_LOCALES as $locale) {
        foreach (glob(lang_path("{$locale}/*.php")) ?: [] as $path) {
            $name = basename($path);

            // The framework supplies the english validation messages; the attribute names have their own test
            if (in_array($name, ['validation.php', 'validation_attributes.php'], true)) {
                continue;
            }

            $files[$name][$locale] = flattenLangLines(require $path);
        }
    }

    ksort($files);

    return $files;
}

it('has every language file in all the languages', function (): void {
    $missing = [];

    foreach (langDictionaries() as $name => $byLocale) {
        foreach (PARITY_LOCALES as $locale) {
            if (! isset($byLocale[$locale])) {
                $missing[] = "{$locale}/{$name}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('has the same keys in every language of a file', function (): void {
    $problems = [];

    foreach (langDictionaries() as $name => $byLocale) {
        $union = array_keys(array_replace(...array_values($byLocale)));

        foreach ($byLocale as $locale => $dict) {
            foreach (array_diff($union, array_keys($dict)) as $key) {
                $problems[] = "{$locale}/{$name}: {$key}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('keeps the placeholders and has no empty text in every language', function (): void {
    $problems = [];

    foreach (langDictionaries() as $name => $byLocale) {
        foreach ($byLocale as $locale => $dict) {
            foreach ($dict as $key => $text) {
                if (trim($text) === '') {
                    $problems[] = "{$locale}/{$name}: {$key} is empty";

                    continue;
                }

                $reference = $byLocale['it'][$key] ?? null;

                if ($reference === null || $locale === 'it') {
                    continue;
                }

                preg_match_all('/:[A-Za-z_]+/', $reference, $expected);
                preg_match_all('/:[A-Za-z_]+/', $text, $actual);
                sort($expected[0]);
                sort($actual[0]);

                if ($expected[0] !== $actual[0]) {
                    $problems[] = "{$locale}/{$name}: {$key} placeholders differ from it";
                }
            }
        }
    }

    expect($problems)->toBe([]);
});
