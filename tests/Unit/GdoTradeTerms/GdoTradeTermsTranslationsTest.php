<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\GdoTradeTermsFrontendTranslations;
use Modules\GdoTradeTerms\Domain\Enums\BaselineSource;
use Modules\GdoTradeTerms\Domain\Enums\ContractStatus;
use Modules\GdoTradeTerms\Domain\Enums\ContributionMode;
use Modules\GdoTradeTerms\Domain\Enums\DateBasis;
use Modules\GdoTradeTerms\Domain\Enums\DeliveryPointType;
use Modules\GdoTradeTerms\Domain\Enums\FixedAccrual;
use Modules\GdoTradeTerms\Domain\Enums\Jurisdiction;
use Modules\GdoTradeTerms\Domain\Enums\LineStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionType;
use Modules\GdoTradeTerms\Domain\Enums\RebateBasis;
use Modules\GdoTradeTerms\Domain\Enums\RunStatus;
use Modules\GdoTradeTerms\Domain\Enums\SettlementKind;
use Modules\GdoTradeTerms\Domain\Enums\SettlementLineStatus;
use Modules\GdoTradeTerms\Domain\Enums\SettlementStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Domain\Enums\TaxTreatment;
use Modules\GdoTradeTerms\Domain\Enums\TierMode;
use Modules\GdoTradeTerms\Domain\Enums\Treatment;
use Modules\GdoTradeTerms\Domain\Enums\VolumeMode;

/**
 * Every text of the module lives in the seeders and the backend dictionaries (it, en, de, fr), never in the code or in the
 * frontend files: these tests fail when an enum case or an error code is added without its four translations.
 */
it('has every text of the frontend dictionary in the four languages and defined once', function (): void {
    $keys = array_keys(GdoTradeTermsFrontendTranslations::definitions());

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $texts = GdoTradeTermsFrontendTranslations::all($locale);
        expect(array_keys($texts))->toBe($keys);

        foreach ($texts as $key => $text) {
            expect(trim($text))->not->toBe('', "{$locale} {$key}");
        }
    }

    $others = array_keys(FrontendTranslationSeeder::dictionary('en'));
    expect(count($others))->toBeGreaterThan(count($keys))
        ->and(count(array_filter($keys, static fn (string $key): bool => ! in_array($key, $others, true))))->toBe(0, 'the seeder serves every key of the module');
});

it('translates every case of every enumeration', function (): void {
    $groups = [
        'contractStatus' => ContractStatus::class, 'jurisdiction' => Jurisdiction::class, 'tierMode' => TierMode::class, 'basis' => RebateBasis::class,
        'volumeMode' => VolumeMode::class, 'baselineSource' => BaselineSource::class, 'promotionType' => PromotionType::class, 'promotionStatus' => PromotionStatus::class,
        'dateBasis' => DateBasis::class, 'contributionMode' => ContributionMode::class, 'fixedAccrual' => FixedAccrual::class, 'runStatus' => RunStatus::class,
        'lineStatus' => LineStatus::class, 'sourceType' => SourceType::class, 'settlementKind' => SettlementKind::class, 'settlementStatus' => SettlementStatus::class,
        'settlementLineStatus' => SettlementLineStatus::class, 'treatment' => Treatment::class, 'taxTreatment' => TaxTreatment::class, 'deliveryPointType' => DeliveryPointType::class,
    ];
    $definitions = GdoTradeTermsFrontendTranslations::definitions();

    foreach ($groups as $group => $enum) {
        foreach ($enum::values() as $value) {
            expect($definitions)->toHaveKey("gdoTradeTerms.{$group}.{$value}");
        }
    }
});

it('translates every error code the module can raise, in the frontend and the backend dictionaries', function (): void {
    $codes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('modules/79_GdoTradeTerms/src'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with((string) $file, '.php') && preg_match_all("/new GdoTradeTermsException\('([a-z_]+)'/", (string) file_get_contents((string) $file), $matches)) {
            array_push($codes, ...$matches[1]);
        }
    }

    $codes = array_values(array_unique($codes));
    $definitions = GdoTradeTermsFrontendTranslations::definitions();

    expect($codes)->not->toBeEmpty();

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $errors = (require lang_path("{$locale}/gdo_trade_terms.php"))['errors'];

        foreach ($codes as $code) {
            expect($errors)->toHaveKey($code);
        }
    }

    foreach ($codes as $code) {
        expect($definitions)->toHaveKey("gdoTradeTerms.error.{$code}");
    }
});

it('keeps the backend dictionaries of the four languages in step', function (): void {
    $reference = require lang_path('en/gdo_trade_terms.php');
    $flatten = static function (array $lines, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($lines as $key => $value) {
            is_array($value) ? array_push($keys, ...$flatten($value, "{$prefix}{$key}.")) : $keys[] = "{$prefix}{$key}";
        }

        return $keys;
    };

    foreach (['it', 'de', 'fr'] as $locale) {
        expect($flatten(require lang_path("{$locale}/gdo_trade_terms.php")))->toBe($flatten($reference), $locale);
    }
});
