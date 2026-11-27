<?php

declare(strict_types=1);

use Database\Seeders\ForeignVatRefundReferenceSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ForeignVatRefund\Models\CountryVatRefundRule;

uses(RefreshDatabase::class);

function fvGlobalRules(): Builder
{
    return CountryVatRefundRule::withoutGlobalScopes()->whereNull('tenant_id');
}

test('the seeder builds the matrix of the three corridors and no domestic rows', function (): void {
    (new ForeignVatRefundReferenceSeeder)->run();

    expect(fvGlobalRules()->count())->toBe(140)
        ->and(fvGlobalRules()->whereColumn('home_country_code', 'refund_country_code')->count())->toBe(0)
        ->and(fvGlobalRules()->where('home_country_code', 'IT')->where('refund_country_code', 'CH')->pluck('regime_type')->unique()->map->value->all())->toBe(['CH_ESTV_RECIPROCITY'])
        ->and(fvGlobalRules()->where('home_country_code', 'CH')->where('refund_country_code', 'IT')->pluck('regime_type')->unique()->map->value->all())->toBe(['IT_ART_38_TER']);
});

test('the Swiss ESTV rules have the CHF 500 minimum, the 30 June deadline, the representative and the certificate', function (): void {
    (new ForeignVatRefundReferenceSeeder)->run();

    $rule = fvGlobalRules()->where('home_country_code', 'IT')->where('refund_country_code', 'CH')->where('expense_category_code', 6)->firstOrFail();

    expect($rule->currency_code)->toBe('CHF')
        ->and((string) $rule->min_annual_amount)->toBe('500.00')
        ->and($rule->min_quarterly_amount)->toBeNull()
        ->and([$rule->filing_deadline_month, $rule->filing_deadline_day])->toBe([6, 30])
        ->and($rule->requires_fiscal_representative)->toBeTrue()
        ->and($rule->requires_taxable_status_certificate)->toBeTrue()
        ->and($rule->vat_rates)->toContain('8.1')->toContain('2.6')->toContain('3.8');
});

test('the EU rules have the 50 and 400 euro minimums and the 30 September deadline', function (): void {
    (new ForeignVatRefundReferenceSeeder)->run();

    $rule = fvGlobalRules()->where('home_country_code', 'IT')->where('refund_country_code', 'DE')->where('expense_category_code', 6)->firstOrFail();

    expect($rule->currency_code)->toBe('EUR')
        ->and((string) $rule->min_annual_amount)->toBe('50.00')
        ->and((string) $rule->min_quarterly_amount)->toBe('400.00')
        ->and([$rule->filing_deadline_month, $rule->filing_deadline_day])->toBe([9, 30]);
});

test('the seeder is idempotent', function (): void {
    (new ForeignVatRefundReferenceSeeder)->run();
    (new ForeignVatRefundReferenceSeeder)->run();

    expect(fvGlobalRules()->count())->toBe(140);
});
