<?php

declare(strict_types=1);

use Database\Seeders\Translations\FranchiseNetworkFrontendTranslations;

const FRN_LOCALES = ['it', 'de', 'fr', 'en'];

/**
 * @return array<string, mixed>
 */
function frnLang(string $locale): array
{
    return require dirname(__DIR__, 3).'/lang/'.$locale.'/franchise_network.php';
}

/**
 * @param  array<string, mixed>  $array
 * @return list<string>
 */
function frnFlatKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        is_array($value) ? array_push($keys, ...frnFlatKeys($value, $prefix.$key.'.')) : $keys[] = $prefix.$key;
    }

    sort($keys);

    return $keys;
}

it('keeps the four backend dictionaries on the same keys', function (): void {
    $reference = frnFlatKeys(frnLang('en'));

    foreach (FRN_LOCALES as $locale) {
        expect(frnFlatKeys(frnLang($locale)))->toBe($reference, $locale);
    }
});

it('has a message in every language for each error code thrown by the module', function (): void {
    $codes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/modules/81_FranchiseNetwork/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            preg_match_all("/new FranchiseNetworkException\('([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $matches);
            array_push($codes, ...$matches[1]);
        }
    }

    expect($codes)->not->toBeEmpty();

    foreach (FRN_LOCALES as $locale) {
        $errors = frnLang($locale)['errors'];

        foreach (array_unique($codes) as $code) {
            expect($errors)->toHaveKey($code, $locale.':'.$code);
        }
    }
});

it('serves the same frontend keys and placeholders in the four languages', function (): void {
    $reference = FranchiseNetworkFrontendTranslations::all('en');

    expect($reference)->not->toBeEmpty();

    foreach (FRN_LOCALES as $locale) {
        $texts = FranchiseNetworkFrontendTranslations::all($locale);

        expect(array_keys($texts))->toEqualCanonicalizing(array_keys($reference), $locale);

        foreach ($reference as $key => $text) {
            preg_match_all('/\{(\w+)\}/', (string) $text, $expected);
            preg_match_all('/\{(\w+)\}/', (string) $texts[$key], $actual);
            expect($actual[1])->toEqualCanonicalizing($expected[1], $locale.':'.$key);
        }
    }
});
