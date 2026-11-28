<?php

declare(strict_types=1);

use Database\Seeders\Translations\WithholdingRecipientsFrontendTranslations;
use Modules\WithholdingRecipients\Domain\Enums\ActivityType;
use Modules\WithholdingRecipients\Domain\Enums\EnasarcoMandate;
use Modules\WithholdingRecipients\Domain\Enums\InlNotificationStatus;
use Modules\WithholdingRecipients\Domain\Enums\Jurisdiction;
use Modules\WithholdingRecipients\Domain\Enums\RecipientRegime;

/**
 * @return list<string>
 */
function wrSourceMatches(string $pattern): array
{
    $root = dirname(__DIR__, 3).'/modules/89_WithholdingRecipients/src';
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
    $definitions = WithholdingRecipientsFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('');
        }
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(WithholdingRecipientsFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('rejects an unsupported locale', function (): void {
    WithholdingRecipientsFrontendTranslations::all('es');
})->throws(InvalidArgumentException::class);

it('has a label for every value of every enumeration the API reports', function (): void {
    $keys = array_keys(WithholdingRecipientsFrontendTranslations::definitions());
    $groups = [
        'regime' => RecipientRegime::values(),
        'jurisdiction' => array_map(static fn (Jurisdiction $case): string => $case->value, Jurisdiction::cases()),
        'activityType' => array_map(static fn (ActivityType $case): string => $case->value, ActivityType::cases()),
        'enasarcoMandate' => array_map(static fn (EnasarcoMandate $case): string => $case->value, EnasarcoMandate::cases()),
        'inlStatus' => array_map(static fn (InlNotificationStatus $case): string => $case->value, InlNotificationStatus::cases()),
        'certStatus' => ['active', 'expiring', 'expired', 'upcoming'],
        'causal' => (array) config('withholding_recipients.cu_causals'),
    ];

    foreach ($groups as $group => $values) {
        foreach ($values as $value) {
            expect($keys)->toContain("withholdingRecipients.{$group}.{$value}");
        }
    }
});

it('has a label for every alert, warning, note and threshold code the calculators and the dashboard report', function (): void {
    $keys = array_keys(WithholdingRecipientsFrontendTranslations::definitions());

    $codes = [
        'warning' => wrSourceMatches("/warnings\\[\\] = '([a-z0-9_]+)'/"),
        'note' => wrSourceMatches("/notes\\[\\] = '([a-z0-9_]+)'/"),
        'alert' => wrSourceMatches("/alerts\\[\\] = '([a-z0-9_]+)'/"),
        'threshold' => wrSourceMatches("/->threshold\\('([a-z0-9_]+)'/"),
    ];

    // The agent notes pick between two codes with a ternary, the thresholds of the dashboard are built by key.
    $codes['note'] = array_merge($codes['note'], ['agent_reduced_base_20', 'agent_standard_base_50', 'royalty_under_35', 'royalty_35_and_over', 'sports_inps_full_base', 'sports_inps_half_base']);
    $codes['threshold'] = array_merge($codes['threshold'], ['occasional_5000', 'sports_5000', 'sports_15000', 'avs_2300', 'enasarco_ceiling']);

    foreach ($codes as $group => $list) {
        expect($list)->not->toBeEmpty();

        foreach (array_unique($list) as $code) {
            expect($keys)->toContain("withholdingRecipients.{$group}.{$code}");
        }
    }
});

it('has a frontend text and a backend message for every error code of the module, in all four languages', function (): void {
    $root = dirname(__DIR__, 3);
    $codes = wrSourceMatches("/new WithholdingRecipientException\\([^;]*?'([a-z_]+)'(?:, \\d+)?\\)/s");
    expect($codes)->not->toBeEmpty();

    $keys = array_keys(WithholdingRecipientsFrontendTranslations::definitions());
    $italian = require $root.'/lang/it/withholding_recipients.php';

    foreach ($codes as $code) {
        expect($keys)->toContain("withholdingRecipients.error.{$code}")->and(array_keys($italian['errors']))->toContain($code);
    }

    foreach (['en', 'de', 'fr'] as $locale) {
        $dictionary = require $root."/lang/{$locale}/withholding_recipients.php";

        foreach ($italian as $section => $entries) {
            expect(array_keys($dictionary[$section]))->toBe(array_keys($entries));

            foreach ($dictionary[$section] as $text) {
                expect(trim((string) $text))->not->toBe('');
            }
        }
    }
});

it('has the validation attribute of every field of the form requests in all four languages', function (): void {
    $root = dirname(__DIR__, 3);
    $fields = [];

    foreach (glob($root.'/modules/89_WithholdingRecipients/src/Http/Requests/*.php') ?: [] as $file) {
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
