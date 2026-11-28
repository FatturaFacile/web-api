<?php

declare(strict_types=1);

use Modules\DairyCheeseManufacturing\Domain\Enums\Authority;
use Modules\DairyCheeseManufacturing\Domain\Enums\PricingComponent;

/**
 * @return list<string>
 */
function dcSourceMatches(string $pattern): array
{
    $root = dirname(__DIR__, 3).'/modules/90_DairyCheeseManufacturing/src';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches);
            $found = array_merge($found, $matches[1]);
        }
    }

    return array_values(array_unique($found));
}

it('has a backend message for every error code the module raises, in all four languages', function (): void {
    $root = dirname(__DIR__, 3);
    $codes = dcSourceMatches("/new DairyCheeseException\\([^;]*?'([a-z_]+)'(?:, \\d+)?\\)/s");
    expect($codes)->not->toBeEmpty();

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $dictionary = require $root."/lang/{$locale}/dairy_cheese.php";

        foreach ($codes as $code) {
            expect($dictionary['errors'])->toHaveKey($code);
        }
    }
});

it('has a message for every key the controllers read', function (): void {
    $root = dirname(__DIR__, 3);
    $keys = dcSourceMatches("/message\\('([a-z_]+)'\\)/");
    $keys = array_merge($keys, ['submission_failed', 'submission_generated']);
    expect($keys)->not->toBeEmpty();

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $dictionary = require $root."/lang/{$locale}/dairy_cheese.php";

        foreach ($keys as $key) {
            expect($dictionary['messages'])->toHaveKey($key);
        }
    }
});

it('keeps the same structure and non empty texts in the four languages', function (): void {
    $root = dirname(__DIR__, 3);
    $italian = require $root.'/lang/it/dairy_cheese.php';

    foreach (['en', 'de', 'fr'] as $locale) {
        $dictionary = require $root."/lang/{$locale}/dairy_cheese.php";

        foreach ($italian as $section => $entries) {
            expect(array_keys($dictionary[$section]))->toBe(array_keys($entries));

            foreach ($dictionary[$section] as $text) {
                expect(trim((string) $text))->not->toBe('');
            }
        }
    }
});

it('names every pricing component of a settlement line', function (): void {
    $root = dirname(__DIR__, 3);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $dictionary = require $root."/lang/{$locale}/dairy_cheese.php";

        foreach (PricingComponent::values() as $component) {
            expect($dictionary['settlement_lines'])->toHaveKey($component);
        }

        expect($dictionary['settlement_lines'])->toHaveKey('base');
    }
});

it('offers label keys for every value of an enumeration', function (): void {
    $options = Authority::options('authority');

    expect($options)->toBe([
        ['value' => 'dbmilch', 'label_key' => 'DAIRY.ENUM.AUTHORITY.DBMILCH'],
        ['value' => 'sian', 'label_key' => 'DAIRY.ENUM.AUTHORITY.SIAN'],
    ]);
});

it('has the validation attribute of every field of the form requests in all four languages', function (): void {
    $root = dirname(__DIR__, 3);
    $fields = [];

    foreach (glob($root.'/modules/90_DairyCheeseManufacturing/src/Http/Requests/*.php') ?: [] as $file) {
        preg_match_all("/^\\s+'([a-z_.*0-9]+)' => \\[/m", (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $key) {
            $leaf = substr($key, (int) strrpos('.'.$key, '.'));

            if ($leaf !== '*') {
                $fields[] = $leaf;
            }
        }
    }

    $fields = array_values(array_unique($fields));
    expect($fields)->not->toBeEmpty();

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $attributes = require $root."/lang/{$locale}/validation_attributes.php";

        foreach ($fields as $field) {
            expect($attributes)->toHaveKey($field);
        }
    }
});
