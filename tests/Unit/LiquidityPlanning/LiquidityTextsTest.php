<?php

declare(strict_types=1);

use Database\Seeders\Translations\LiquidityPlanningFrontendTranslations;
use Database\Seeders\Translations\LiquidityPlanningTranslations;
use Modules\LiquidityPlanning\Services\LiquidityTexts;

it('fills the placeholders of a seeded text in the requested locale', function (): void {
    $text = LiquidityTexts::get('lpApi.alert.dscr.message', 'en', ['value' => '0.80', 'threshold' => '1.00', 'operating_cash_flow' => '800.00', 'debt_service' => '1000.00', 'currency' => 'EUR']);

    expect($text)->toContain('0.80')->and($text)->toContain('1000.00 EUR')->and($text)->not->toContain('{');
    expect(LiquidityTexts::get('lpApi.severity.critical', 'it'))->toBe('Critico');
    expect(LiquidityTexts::get('lpApi.unknown.key', 'xx'))->toBe('lpApi.unknown.key');
});

it('has every backend key in the four locales with a non-empty text', function (): void {
    foreach (LiquidityPlanningTranslations::definitions() as $key => $texts) {
        expect($texts)->toHaveCount(4);

        foreach ($texts as $text) {
            expect(trim($text))->not->toBe('', $key);
        }
    }
});

it('keeps the backend and the web client dictionaries disjoint', function (): void {
    $backend = array_keys(LiquidityPlanningTranslations::definitions());
    $client = array_keys(LiquidityPlanningFrontendTranslations::definitions());

    expect(array_intersect($backend, $client))->toBe([]);
});

it('keeps the same placeholders in the four locales of every backend text', function (): void {
    foreach (LiquidityPlanningTranslations::definitions() as $key => $texts) {
        $sets = array_map(static function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $match);
            sort($match[1]);

            return $match[1];
        }, $texts);

        expect($sets[1])->toBe($sets[0], $key)->and($sets[2])->toBe($sets[0], $key)->and($sets[3])->toBe($sets[0], $key);
    }
});
