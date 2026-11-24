<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;

/**
 * Every validation error of the API is in the language of the request, with the field named in that language.
 */
const VALIDATION_LOCALES = ['it', 'en', 'de', 'fr'];

/**
 * The field names that are keys of the arrays returned by rules() in every FormRequest of the API.
 *
 * @return list<string> last segment of every field
 */
function formRequestFieldNames(): array
{
    $fields = [];

    foreach (['app', 'modules', 'packages'] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_ends_with($path, 'Request.php') || str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'function rules') || ! preg_match('/function rules\(\)[^{]*\{(.*?)\n    \}/s', $source, $body)) {
                continue;
            }

            preg_match_all("/^\s*'([A-Za-z0-9_.*]+)'\s*=>/m", $body[1], $keys);

            foreach ($keys[1] as $key) {
                $leaf = substr($key, (int) strrpos('.'.$key, '.'));

                if ($leaf !== '*') {
                    $fields[$leaf] = true;
                }
            }
        }
    }

    return array_keys($fields);
}

it('names every field of every form request in all the languages', function (): void {
    $fields = formRequestFieldNames();
    expect($fields)->not->toBeEmpty();

    foreach (VALIDATION_LOCALES as $locale) {
        $attributes = require lang_path("{$locale}/validation_attributes.php");
        $missing = array_values(array_filter($fields, fn (string $field): bool => ! isset($attributes[$field]) || trim((string) $attributes[$field]) === ''));

        expect($missing)->toBe([], "Fields without a {$locale} name: ".implode(', ', array_slice($missing, 0, 15)));
    }
});

it('keeps the same fields in the attribute files of all the languages', function (): void {
    $reference = array_keys(require lang_path('it/validation_attributes.php'));
    sort($reference);

    foreach (VALIDATION_LOCALES as $locale) {
        $keys = array_keys(require lang_path("{$locale}/validation_attributes.php"));
        sort($keys);

        expect($keys)->toBe($reference, "Fields of {$locale} differ from it");
    }
});

it('translates every message of the framework into italian, german and french', function (): void {
    $framework = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $flatten = static function (array $lines, string $prefix = '') use (&$flatten): array {
        $flat = [];

        foreach ($lines as $key => $value) {
            if (in_array($key, ['custom', 'attributes'], true)) {
                continue;
            }

            is_array($value) ? $flat += $flatten($value, "{$prefix}{$key}.") : $flat["{$prefix}{$key}"] = $value;
        }

        return $flat;
    };

    $english = $flatten($framework);

    foreach (['it', 'de', 'fr'] as $locale) {
        $messages = $flatten(require lang_path("{$locale}/validation.php"));
        $missing = array_values(array_diff(array_keys($english), array_keys($messages)));

        expect($missing)->toBe([], "Messages missing in {$locale}");

        $lost = [];

        foreach ($english as $key => $text) {
            preg_match_all('/:[a-z_]+/', $text, $placeholders);

            foreach (array_unique($placeholders[0]) as $placeholder) {
                if (! str_contains($messages[$key], $placeholder)) {
                    $lost[] = "{$key} {$placeholder}";
                }
            }
        }

        expect($lost)->toBe([], "Placeholders lost in {$locale}");
    }
});

it('returns the error in the language of the request with the field named in that language', function (string $locale, string $expected): void {
    App::setLocale($locale);

    $validator = Validator::make([], ['company_name' => ['required']]);

    expect($validator->errors()->first('company_name'))->toBe($expected);
})->with([
    'italian' => ['it', 'Il campo ragione sociale è obbligatorio.'],
    'english' => ['en', 'The company name field is required.'],
    'german' => ['de', 'Das Feld Firmenname ist ein Pflichtfeld.'],
    'french' => ['fr', 'Le champ raison sociale est obligatoire.'],
]);

it('names a nested field with the name of its last segment', function (): void {
    App::setLocale('it');

    $validator = Validator::make(['address' => ['street' => '']], ['address.street' => ['required']]);

    expect($validator->errors()->first('address.street'))->toBe('Il campo via è obbligatorio.');
});
