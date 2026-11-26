<?php

declare(strict_types=1);

use Database\Seeders\Translations\ProductCatalogFrontendTranslations;
use Modules\ProductCatalog\Exceptions\CatalogException;

$locales = ['it', 'en', 'de', 'fr'];

it('has a non-empty translation for every key in the four locales', function () use ($locales): void {
    $definitions = ProductCatalogFrontendTranslations::definitions();

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4, $key);

        foreach ($tuple as $text) {
            expect(trim((string) $text))->not->toBe('', $key);
        }
    }

    foreach ($locales as $locale) {
        expect(ProductCatalogFrontendTranslations::all($locale))->toHaveCount(count($definitions));
    }
});

it('keeps the same placeholders in every locale of a key', function (): void {
    foreach (ProductCatalogFrontendTranslations::definitions() as $key => $tuple) {
        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $matches);
            sort($matches[1]);

            return $matches[1];
        }, $tuple);

        expect($placeholders[1])->toBe($placeholders[0], $key)->and($placeholders[2])->toBe($placeholders[0], $key)->and($placeholders[3])->toBe($placeholders[0], $key);
    }
});

it('rejects an unsupported locale', function (): void {
    expect(fn () => ProductCatalogFrontendTranslations::all('es'))->toThrow(InvalidArgumentException::class);
});

it('has every error code of the API in the four backend dictionaries and the frontend dictionary', function () use ($locales): void {
    $en = require lang_path('en/product_catalog.php');
    $codes = array_keys($en['errors']);
    $frontend = ProductCatalogFrontendTranslations::all('en');

    foreach ($locales as $locale) {
        $dictionary = require lang_path($locale.'/product_catalog.php');

        expect(array_keys($dictionary['errors']))->toBe($codes)
            ->and(array_keys($dictionary['messages']))->toBe(array_keys($en['messages']))
            ->and(array_keys($dictionary['import']))->toBe(array_keys($en['import']));
    }

    foreach ($codes as $code) {
        expect($frontend)->toHaveKey('productCatalog.error.'.$code);
    }
});

it('names the units of measure the demo seeder creates', function (): void {
    $frontend = ProductCatalogFrontendTranslations::all('en');

    foreach (['PZ', 'PACK', 'BOX', 'CT', 'PLT', 'SET', 'KG', 'G', 'L', 'ML', 'M', 'H', 'LIC'] as $code) {
        expect($frontend)->toHaveKey('productCatalog.unit.'.$code);
    }
});

it('renders a business error as an RFC 7807 problem with its code and translated detail', function (): void {
    app()->setLocale('en');
    $response = (new CatalogException('sku_taken', 422, ['sku' => 'ABC']))->render(request());

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and($response->getData(true)['code'])->toBe('sku_taken')
        ->and($response->getData(true)['detail'])->toBe('The SKU ABC is already in use.');
});
