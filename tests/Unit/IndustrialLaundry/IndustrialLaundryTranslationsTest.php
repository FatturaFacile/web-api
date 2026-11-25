<?php

declare(strict_types=1);

use Database\Seeders\FrontendTranslationSeeder;
use Database\Seeders\Translations\IndustrialLaundryFrontendTranslations;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\BillingLineKind;
use Modules\IndustrialLaundry\Domain\Enums\BillingModel;
use Modules\IndustrialLaundry\Domain\Enums\BillingRunStatus;
use Modules\IndustrialLaundry\Domain\Enums\CertificationStatus;
use Modules\IndustrialLaundry\Domain\Enums\CustomerSegment;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\RabcRiskZone;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;
use Modules\IndustrialLaundry\Services\WashProgramCatalog;

/**
 * Every text of the module lives in the seeders and the backend dictionaries (it, en, de, fr), never in the code or in the
 * frontend files: these tests fail when an enum case, an error code, a finding or a rejection reason is added without its
 * four translations.
 *
 * @param  string  $pattern  Regular expression with one capturing group, applied to every PHP file under the module.
 * @return list<string>
 */
function ilrCodesIn(string $pattern, string $folder = 'modules/80_IndustrialLaundry/src'): array
{
    $codes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($folder), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with((string) $file, '.php') && preg_match_all($pattern, (string) file_get_contents((string) $file), $matches)) {
            array_push($codes, ...$matches[1]);
        }
    }

    return array_values(array_unique($codes));
}

it('has every text of the frontend dictionary in the four languages and defined once', function (): void {
    $keys = array_keys(IndustrialLaundryFrontendTranslations::definitions());

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $texts = IndustrialLaundryFrontendTranslations::all($locale);
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
        'articleType' => ArticleType::class, 'lifecycleState' => LifecycleState::class, 'scanDirection' => ScanDirection::class, 'billingModel' => BillingModel::class,
        'customerSegment' => CustomerSegment::class, 'rabcRiskZone' => RabcRiskZone::class, 'certificationStatus' => CertificationStatus::class,
        'billingLineKind' => BillingLineKind::class, 'billingRunStatus' => BillingRunStatus::class,
    ];
    $definitions = IndustrialLaundryFrontendTranslations::definitions();

    foreach ($groups as $group => $enum) {
        foreach ($enum::values() as $value) {
            expect($definitions)->toHaveKey("industrialLaundry.{$group}.{$value}");
        }
    }

    foreach (['limit_reached', 'near_limit'] as $status) {
        expect($definitions)->toHaveKey("industrialLaundry.ppeStatus.{$status}");
    }
});

it('names every wash program of the catalog', function (): void {
    $definitions = IndustrialLaundryFrontendTranslations::definitions();

    foreach (WashProgramCatalog::defaults() as $program) {
        expect($definitions)->toHaveKey("industrialLaundry.program.{$program['code']}");
    }
});

it('translates every error code the module can raise, in the frontend and the backend dictionaries', function (): void {
    $codes = ilrCodesIn("/new IndustrialLaundryException\('([a-z_]+)'/");
    $definitions = IndustrialLaundryFrontendTranslations::definitions();

    expect($codes)->not->toBeEmpty();

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $errors = (require lang_path("{$locale}/industrial_laundry.php"))['errors'];

        foreach ($codes as $code) {
            expect($errors)->toHaveKey($code, "{$locale} backend");
        }
    }

    foreach ($codes as $code) {
        expect($definitions)->toHaveKey("industrialLaundry.error.{$code}");
    }
});

it('translates every finding the compliance strategies can raise', function (): void {
    $codes = ilrCodesIn("/new ComplianceFinding\('([a-z_]+)'/", 'modules/80_IndustrialLaundry/src/Compliance');
    $codes[] = 'customer_unresolved';
    $definitions = IndustrialLaundryFrontendTranslations::definitions();

    expect(count($codes))->toBeGreaterThan(5);

    foreach (array_unique($codes) as $code) {
        expect($definitions)->toHaveKey("industrialLaundry.finding.{$code}");
    }
});

it('translates every reason the scan can reject a read for', function (): void {
    preg_match_all("/return '([a-z_]+)';/", (string) file_get_contents(base_path('modules/80_IndustrialLaundry/src/Services/RfidTunnelGateScanService.php')), $found);
    $reasons = array_values(array_unique([...$found[1], 'invalid_epc', 'foreign_antenna', 'out_of_range', 'duplicate', 'unknown_tag']));
    $definitions = IndustrialLaundryFrontendTranslations::definitions();

    expect($reasons)->toContain('condemned', 'customer_mismatch', 'ppe_wash_limit_reached', 'rabc_not_certified');

    foreach ($reasons as $reason) {
        expect($definitions)->toHaveKey("industrialLaundry.rejection.{$reason}");
    }
});

it('keeps the backend dictionaries of the four languages in step and the article types and invoice lines translated', function (): void {
    $reference = require lang_path('en/industrial_laundry.php');
    $flatten = static function (array $lines, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($lines as $key => $value) {
            is_array($value) ? array_push($keys, ...$flatten($value, "{$prefix}{$key}.")) : $keys[] = "{$prefix}{$key}";
        }

        return $keys;
    };

    foreach (['it', 'de', 'fr'] as $locale) {
        expect($flatten(require lang_path("{$locale}/industrial_laundry.php")))->toBe($flatten($reference), $locale);
    }

    foreach (ArticleType::values() as $type) {
        expect($reference['article_types'])->toHaveKey($type);
    }

    foreach (BillingLineKind::values() as $kind) {
        expect($reference['invoice']['line'])->toHaveKey($kind);
    }
});

it('keeps the placeholders of a text identical in the four languages', function (): void {
    foreach (IndustrialLaundryFrontendTranslations::definitions() as $key => $tuple) {
        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/:[a-z_]+/', $text, $found);
            sort($found[0]);

            return $found[0];
        }, $tuple);

        expect($placeholders[1])->toBe($placeholders[0], "it {$key}")
            ->and($placeholders[2])->toBe($placeholders[0], "de {$key}")
            ->and($placeholders[3])->toBe($placeholders[0], "fr {$key}");
    }
});
