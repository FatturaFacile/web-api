<?php

declare(strict_types=1);

use Modules\Intercompany\Enums\Standard;
use Modules\Intercompany\Services\StandardProfile;

/**
 * The presentation profiles of the three reporting standards.
 */
test('each standard has its own framework, layout and goodwill treatment', function (): void {
    $fer = StandardProfile::for(Standard::Fer30);
    $ifrs = StandardProfile::for(Standard::Ifrs10);
    $oic = StandardProfile::for(Standard::Oic17);

    expect($fer['framework'])->toBe('Swiss GAAP FER 30')->and($ifrs['framework'])->toBe('IFRS 10')->and($oic['framework'])->toBe('OIC 17')
        ->and($fer['income_layout'])->toBe('function')->and($ifrs['income_layout'])->toBe('function')->and($oic['income_layout'])->toBe('nature')
        ->and($fer['goodwill_treatment'])->toBe('offset_or_capitalise')
        ->and($ifrs['goodwill_treatment'])->toBe('capitalise_impairment_only')
        ->and($oic['goodwill_treatment'])->toBe('capitalise_amortise')
        ->and($fer['extraordinary_items'])->toBeTrue()->and($ifrs['extraordinary_items'])->toBeFalse()->and($oic['extraordinary_items'])->toBeFalse();
});

test('the balance sheet order and the income sections follow the layout', function (): void {
    expect(StandardProfile::for(Standard::Fer30)['balance_sheet_order'])->toBe(['assets', 'liabilities', 'equity'])
        ->and(StandardProfile::for(Standard::Ifrs10)['balance_sheet_order'])->toBe(['assets', 'equity', 'liabilities'])
        ->and(StandardProfile::for(Standard::Oic17)['income_sections'])->toBe(['value_of_production', 'cost_of_production'])
        ->and(StandardProfile::for(Standard::Ifrs10)['income_sections'])->toBe(['revenue', 'cogs', 'operating_expenses']);
});

test('every standard prepares the cash flow by the indirect method and has translation keys', function (): void {
    foreach (Standard::cases() as $standard) {
        $profile = StandardProfile::for($standard);

        expect($profile['cash_flow_method'])->toBe('indirect')
            ->and($profile['name_key'])->toBe("intercompany.standard.{$standard->value}")
            ->and($profile['translation_reserve_label_key'])->toBe("intercompany.statements.{$standard->value}.translation_reserve")
            ->and($profile['nci_in_equity'])->toBeTrue();
    }
});

test('the standards are the three codes of the API', function (): void {
    expect(Standard::codes())->toBe(['fer30', 'ifrs10', 'oic17']);
});
