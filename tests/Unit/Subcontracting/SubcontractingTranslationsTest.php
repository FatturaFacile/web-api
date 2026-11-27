<?php

declare(strict_types=1);

use Database\Seeders\Translations\EnglishFrontendTranslations;
use Database\Seeders\Translations\FrenchFrontendTranslations;
use Database\Seeders\Translations\GermanFrontendTranslations;
use Database\Seeders\Translations\ItalianFrontendTranslations;
use Database\Seeders\Translations\SubcontractingFrontendTranslations;
use Modules\Logistics\Enums\DeliveryNoteType;
use Modules\Logistics\Enums\TransportReason;
use Modules\Subcontracting\Domain\Enums\CustomsProcessingRegime;
use Modules\Subcontracting\Domain\Enums\DeadlineStatus;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\LedgerMovementType;
use Modules\Subcontracting\Domain\Enums\OrderStatus;
use Modules\Subcontracting\Domain\Enums\SubcontractingType;
use Modules\Subcontracting\Exceptions\SubcontractingException;

it('carries every subcontracting key in the four languages with the same placeholders', function (): void {
    $definitions = SubcontractingFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('', $key);
        }

        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{(\w+)\}/', $text, $found);
            sort($found[1]);

            return $found[1];
        }, $tuple);

        expect($placeholders[1])->toBe($placeholders[0], $key)
            ->and($placeholders[2])->toBe($placeholders[0], $key)
            ->and($placeholders[3])->toBe($placeholders[0], $key);
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(SubcontractingFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('does not repeat a key of the base dictionaries', function (): void {
    $own = array_keys(SubcontractingFrontendTranslations::definitions());

    foreach ([ItalianFrontendTranslations::all(), EnglishFrontendTranslations::all(), GermanFrontendTranslations::all(), FrenchFrontendTranslations::all()] as $dictionary) {
        expect(array_intersect($own, array_keys($dictionary)))->toBe([]);
    }
});

it('rejects a language the dictionary does not have', function (): void {
    SubcontractingFrontendTranslations::all('es');
})->throws(InvalidArgumentException::class);

it('has a label for every value the subcontracting API reports', function (): void {
    $keys = array_keys(SubcontractingFrontendTranslations::definitions());

    $enums = [
        'direction' => Direction::class,
        'type' => SubcontractingType::class,
        'status' => OrderStatus::class,
        'customsRegime' => CustomsProcessingRegime::class,
        'movementType' => LedgerMovementType::class,
        'deadlineStatus' => DeadlineStatus::class,
    ];

    foreach ($enums as $group => $enum) {
        foreach ($enum::cases() as $case) {
            expect($keys)->toContain("subcontracting.{$group}.{$case->value}");
        }
    }

    // The causals of the DDTs of the module and the new transport reason of the returned processed goods.
    foreach (['subcontracting', 'subcontractor_return', 'subcontracted_goods_return'] as $causal) {
        expect($keys)->toContain("subcontracting.causal.{$causal}")
            ->and(TransportReason::tryFrom($causal))->not->toBeNull();
    }

    expect(DeliveryNoteType::SubcontractingFinishedReturn->defaultTransportReason())->toBe(TransportReason::SubcontractedGoodsReturn)
        ->and(DeliveryNoteType::SubcontractingFinishedReturn->numberingSection())->toBe('ddt_subcontract')
        ->and(DeliveryNoteType::SubcontractingFinishedReturn->requiresCustomer())->toBeFalse()
        ->and(TransportReason::SubcontractedGoodsReturn->label())->toBe('Reso lavorato');
});

it('translates every error code the module throws, in the backend and the frontend dictionary', function (): void {
    $codes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('modules/86_Subcontracting/src'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getPathname(), '.php')) {
            continue;
        }

        preg_match_all("/new SubcontractingException\('([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $found);
        array_push($codes, ...$found[1]);
    }

    $codes = array_values(array_unique($codes));
    expect($codes)->not->toBeEmpty();

    $keys = array_keys(SubcontractingFrontendTranslations::definitions());

    foreach ($codes as $code) {
        expect($keys)->toContain("subcontracting.error.{$code}");

        foreach (['en', 'it', 'de', 'fr'] as $locale) {
            app()->setLocale($locale);
            expect(__("subcontracting.errors.{$code}"))->not->toBe("subcontracting.errors.{$code}", "{$locale} {$code}");
        }
    }

    $problem = (new SubcontractingException('dispatch_exceeds_plan', 422, ['product' => 'Steel', 'max' => '8.0000']))->render(request());
    expect($problem->getStatusCode())->toBe(422)
        ->and($problem->getData(true)['code'])->toBe('dispatch_exceeds_plan')
        ->and($problem->getData(true)['params']['max'])->toBe('8.0000');
});

it('keeps the backend dictionaries of the four languages in step', function (): void {
    $flatten = static function (array $tree, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            array_push($keys, ...(is_array($value) ? $flatten($value, $path) : [$path]));
        }

        sort($keys);

        return $keys;
    };

    $english = $flatten(require base_path('lang/en/subcontracting.php'));
    expect($english)->not->toBeEmpty();

    foreach (['it', 'de', 'fr'] as $locale) {
        expect($flatten(require base_path("lang/{$locale}/subcontracting.php")))->toBe($english, $locale);
    }
});
