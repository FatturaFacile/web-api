<?php

declare(strict_types=1);

use Database\Seeders\Translations\BeekeepingFrontendTranslations;
use Modules\Beekeeping\Domain\Enums\ApiaryType;
use Modules\Beekeeping\Domain\Enums\BotanicalOrigin;
use Modules\Beekeeping\Domain\Enums\Country;
use Modules\Beekeeping\Domain\Enums\FeedingType;
use Modules\Beekeeping\Domain\Enums\GeneticLine;
use Modules\Beekeeping\Domain\Enums\HiveOrigin;
use Modules\Beekeeping\Domain\Enums\HiveStatus;
use Modules\Beekeeping\Domain\Enums\HiveType;
use Modules\Beekeeping\Domain\Enums\MovementKind;
use Modules\Beekeeping\Domain\Enums\MovementStatus;
use Modules\Beekeeping\Domain\Enums\MovementSubject;
use Modules\Beekeeping\Domain\Enums\ProductKind;
use Modules\Beekeeping\Domain\Enums\QueenStatus;
use Modules\Beekeeping\Domain\Enums\SaleChannel;
use Modules\Beekeeping\Domain\Enums\TaxRegime;
use Modules\Beekeeping\Domain\Enums\TreatmentProduct;
use Modules\Beekeeping\Domain\Enums\VarroaCountType;

/**
 * @return list<string>
 */
function beeSourceMatches(string $pattern): array
{
    $root = dirname(__DIR__, 3).'/modules/90_Beekeeping/src';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches);
            $found = array_merge($found, $matches[1]);
        }
    }

    return array_values(array_unique($found));
}

it('has the four languages for every frontend key and no empty text', function (): void {
    $definitions = BeekeepingFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('');
        }
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(BeekeepingFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('rejects an unsupported locale', function (): void {
    BeekeepingFrontendTranslations::all('es');
})->throws(InvalidArgumentException::class);

it('uses the same placeholders in every language', function (): void {
    foreach (BeekeepingFrontendTranslations::definitions() as $key => $tuple) {
        $placeholders = array_map(static function (string $text): string {
            preg_match_all('/\{(\w+)\}/', $text, $matches);
            sort($matches[1]);

            return implode(',', $matches[1]);
        }, $tuple);

        expect(count(array_unique($placeholders)))->toBe(1, "{$key} has different placeholders per language");
    }
});

it('has a label for every value of every enumeration the API reports', function (): void {
    $keys = array_keys(BeekeepingFrontendTranslations::definitions());
    $groups = [
        'country' => Country::values(),
        'apiaryType' => ApiaryType::values(),
        'hiveType' => HiveType::values(),
        'hiveOrigin' => HiveOrigin::values(),
        'hiveStatus' => HiveStatus::values(),
        'queenStatus' => QueenStatus::values(),
        'geneticLine' => GeneticLine::values(),
        'origin' => BotanicalOrigin::values(),
        'treatmentProduct' => TreatmentProduct::values(),
        'countType' => VarroaCountType::values(),
        'movementKind' => MovementKind::values(),
        'movementSubject' => MovementSubject::values(),
        'movementStatus' => MovementStatus::values(),
        'productKind' => ProductKind::values(),
        'saleChannel' => SaleChannel::values(),
        'taxRegime' => TaxRegime::values(),
        'feedingType' => FeedingType::values(),
        'queenColor' => ['white', 'yellow', 'red', 'green', 'blue'],
        'censusStatus' => ['draft', 'submitted'],
        'quality' => ['ok', 'warning', 'critical', 'unknown'],
        'alert' => ['swarming_risk', 'queenless', 'weak_colony', 'old_queen'],
        'warning' => ['moisture_warning', 'moisture_critical', 'hmf_warning', 'hmf_critical'],
        'taxNote' => ['ch_reduced_rate', 'ch_standard_rate', 'it_ordinary_rate', 'it_exempt_art34_c6', 'it_exemption_threshold_exceeded', 'it_special_agricultural'],
    ];

    foreach ($groups as $group => $values) {
        foreach ($values as $value) {
            expect($keys)->toContain("beekeeping.{$group}.{$value}");
        }
    }
});

it('has a label for every alert code the services report', function (): void {
    $keys = array_keys(BeekeepingFrontendTranslations::definitions());
    $alerts = beeSourceMatches("/alerts\\[\\] = '([a-z_]+)'/");
    $notes = beeSourceMatches("/notes\\[\\] = '([a-z0-9_]+)'/");

    expect($alerts)->not->toBeEmpty()->and($notes)->not->toBeEmpty();

    foreach ($alerts as $code) {
        expect($keys)->toContain("beekeeping.alert.{$code}");
    }

    foreach ($notes as $code) {
        expect($keys)->toContain("beekeeping.taxNote.{$code}");
    }
});

it('has a frontend text and a backend message for every error code of the module, in all four languages', function (): void {
    $root = dirname(__DIR__, 3);
    $codes = beeSourceMatches("/new BeekeepingException\\([^;]*?'([a-z_]+)'(?:, \\d+)?(?:, \\[.*?\\])?\\)/s");
    expect($codes)->not->toBeEmpty();

    $keys = array_keys(BeekeepingFrontendTranslations::definitions());
    $italian = require $root.'/lang/it/beekeeping.php';

    foreach ($codes as $code) {
        expect($keys)->toContain("beekeeping.error.{$code}")->and(array_keys($italian['errors']))->toContain($code);
    }

    foreach (['en', 'de', 'fr'] as $locale) {
        $dictionary = require $root."/lang/{$locale}/beekeeping.php";

        foreach ($italian as $section => $entries) {
            expect(array_keys($dictionary[$section]))->toBe(array_keys($entries));

            foreach ($dictionary[$section] as $text) {
                expect(trim((string) $text))->not->toBe('');
            }
        }
    }
});

it('has the document texts the PDF renderer asks for', function (): void {
    $documents = (require dirname(__DIR__, 3).'/lang/it/beekeeping.php')['documents'];
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/modules/90_Beekeeping/src/Documents/BeekeepingDocumentRenderer.php');
    preg_match_all("/\\\$this->t\\(\\\$locale, '([a-z_]+)'\\)/", $source, $matches);

    foreach (array_unique($matches[1]) as $key) {
        expect($documents)->toHaveKey($key);
    }

    foreach (BotanicalOrigin::values() as $origin) {
        expect($documents)->toHaveKey("origin_{$origin}");
    }
});

it('has the validation attribute of every field of the form requests in all four languages', function (): void {
    $root = dirname(__DIR__, 3);
    $fields = [];

    foreach (glob($root.'/modules/90_Beekeeping/src/Http/Requests/*.php') ?: [] as $file) {
        preg_match_all("/^\\s+(?:\\\$prefix\\.)?'([a-z_]+)' => \\[/m", (string) file_get_contents($file), $matches);
        $fields = array_merge($fields, $matches[1]);
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
