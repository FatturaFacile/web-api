<?php

declare(strict_types=1);

use Database\Seeders\Translations\BudgetingSpendControlFrontendTranslations;
use Database\Seeders\Translations\EnglishFrontendTranslations;
use Database\Seeders\Translations\FrenchFrontendTranslations;
use Database\Seeders\Translations\GermanFrontendTranslations;
use Database\Seeders\Translations\ItalianFrontendTranslations;

it('carries every budget control key in the four languages with the same placeholders', function (): void {
    $definitions = BudgetingSpendControlFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('', $key);
        }

        $placeholders = array_map(static function (string $text): array {
            preg_match_all('/\{\w+}/', $text, $matches);
            sort($matches[0]);

            return $matches[0];
        }, $tuple);

        expect($placeholders[1])->toBe($placeholders[0], $key)->and($placeholders[2])->toBe($placeholders[0], $key)->and($placeholders[3])->toBe($placeholders[0], $key);
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(BudgetingSpendControlFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('does not repeat a key of the dictionaries of the planning screens', function (): void {
    $own = array_keys(BudgetingSpendControlFrontendTranslations::definitions());

    foreach ([ItalianFrontendTranslations::all(), EnglishFrontendTranslations::all(), GermanFrontendTranslations::all(), FrenchFrontendTranslations::all()] as $dictionary) {
        expect(array_intersect($own, array_keys($dictionary)))->toBe([]);
    }
});

it('has a label for every value the budget control API reports', function (): void {
    $keys = array_keys(BudgetingSpendControlFrontendTranslations::definitions());

    $groups = [
        'BUDGETING_CONTROL.MODE_' => ['allow', 'warning', 'hard_block'],
        'BUDGETING_CONTROL.OUTCOME_' => ['ok', 'warning', 'overridden', 'blocked'],
        'BUDGETING_CONTROL.OVERRIDE_' => ['pending', 'approved', 'rejected', 'cancelled', 'consumed'],
        'BUDGETING_CONTROL.SCOPE_' => ['document', 'allowance'],
        'BUDGETING_CONTROL.STAGE_' => ['create', 'submit', 'approve'],
        'BUDGETING_CONTROL.DOCUMENT_' => ['requisition', 'purchase_order'],
        'BUDGETING_CONTROL.COMMITMENT_' => ['open', 'closed', 'released'],
        'BUDGETING_CONTROL.SCENARIO_' => ['best_case', 'worst_case', 'custom'],
        'BUDGETING_CONTROL.GRANULARITY_' => ['monthly', 'quarterly'],
        'BUDGETING_CONTROL.LIGHT_' => ['green', 'amber', 'red'],
        'BUDGETING_CONTROL.IMPORT_STATUS_' => ['imported', 'failed', 'validated'],
        'BUDGETING_CONTROL.IMPORT_MODE_' => ['replace', 'merge'],
        'BUDGETING_CONTROL.AUDIT_' => ['revise', 'scenario', 'transfer', 'grid_saved', 'import', 'roll_forward', 'request', 'approve', 'reject', 'cancel', 'consume', 'create', 'update', 'delete', 'commit', 'release'],
        'BUDGETING.TYPE_' => ['revised', 'scenario'],
    ];

    foreach ($groups as $prefix => $values) {
        foreach ($values as $value) {
            expect($keys)->toContain($prefix.strtoupper($value));
        }
    }
});

it('registers the budget control dictionary in the frontend translation seeder', function (): void {
    expect(file_get_contents(base_path('database/seeders/FrontendTranslationSeeder.php')))->toContain('BudgetingSpendControlFrontendTranslations::all($locale)');
});
