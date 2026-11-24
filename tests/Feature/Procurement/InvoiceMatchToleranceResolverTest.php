<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Procurement\Enums\InvoiceMatchType;
use Modules\Procurement\Enums\ToleranceScope;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Services\InvoiceMatchToleranceResolver;
use Modules\Procurement\Services\ProcurementSettingsService;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/InvoiceMatchingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    matchingSetUp($this);
    $this->resolver = fn (?string $supplierId = null, ?string $category = null, InvoiceMatchType $type = InvoiceMatchType::ThreeWay, ?string $currency = null) => Fixtures::in(
        $this->tenant,
        fn () => (new InvoiceMatchToleranceResolver(app(ProcurementSettingsService::class)))->resolve((string) $this->tenant->id, $supplierId, $category, $type, $currency),
    );
});

it('falls back to the company settings when no rule applies', function (): void {
    Fixtures::in($this->tenant, fn () => ProcurementSetting::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $this->tenant->id], ['price_tolerance_percent' => 1.5, 'price_tolerance_amount' => 4, 'over_receipt_tolerance_percent' => 2]));

    $tolerance = ($this->resolver)((string) $this->supplier->id);

    expect($tolerance->source)->toBe('settings')
        ->and($tolerance->ruleId)->toBeNull()
        ->and($tolerance->pricePercent)->toBe('1.50')
        ->and($tolerance->priceAmount)->toBe('4.00')
        ->and($tolerance->quantityPercent)->toBe('2.00');
});

it('prefers the narrowest scope: named rule, then supplier, then item category, then the whole tenant', function (): void {
    matchingTolerance($this, ['name' => 'Tenant', 'scope' => ToleranceScope::Tenant->value, 'price_tolerance_percent' => 1]);
    expect(($this->resolver)((string) $this->supplier->id, 'packaging')->pricePercent)->toBe('1.00');

    matchingTolerance($this, ['name' => 'Packaging', 'scope' => ToleranceScope::ItemCategory->value, 'item_category' => 'packaging', 'price_tolerance_percent' => 2]);
    expect(($this->resolver)((string) $this->supplier->id, 'packaging')->pricePercent)->toBe('2.00')
        ->and(($this->resolver)((string) $this->supplier->id, 'electronics')->pricePercent)->toBe('1.00');

    matchingTolerance($this, ['name' => 'Supplier', 'scope' => ToleranceScope::Supplier->value, 'supplier_id' => $this->supplier->id, 'price_tolerance_percent' => 3]);
    expect(($this->resolver)((string) $this->supplier->id, 'packaging')->pricePercent)->toBe('3.00');

    $named = matchingTolerance($this, ['name' => 'Named', 'scope' => ToleranceScope::Rule->value, 'supplier_id' => $this->supplier->id, 'item_category' => 'packaging', 'price_tolerance_percent' => 4]);
    $tolerance = ($this->resolver)((string) $this->supplier->id, 'packaging');

    expect($tolerance->pricePercent)->toBe('4.00')
        ->and($tolerance->ruleId)->toBe((string) $named->id)
        ->and($tolerance->source)->toBe('rule')
        // Another category does not fit the named rule: the supplier rule applies.
        ->and(($this->resolver)((string) $this->supplier->id, 'electronics')->pricePercent)->toBe('3.00');
});

it('ignores a supplier rule for another supplier and an inactive rule', function (): void {
    $other = Fixtures::supplier($this->tenant);
    matchingTolerance($this, ['name' => 'Other supplier', 'scope' => ToleranceScope::Supplier->value, 'supplier_id' => $other->id, 'price_tolerance_percent' => 9]);
    matchingTolerance($this, ['name' => 'Off', 'scope' => ToleranceScope::Tenant->value, 'price_tolerance_percent' => 8, 'is_active' => false]);

    expect(($this->resolver)((string) $this->supplier->id)->pricePercent)->toBe('0.00')
        ->and(($this->resolver)((string) $other->id)->pricePercent)->toBe('9.00');
});

it('takes the lowest priority number inside the same scope', function (): void {
    matchingTolerance($this, ['name' => 'Slow', 'scope' => ToleranceScope::Tenant->value, 'priority' => 200, 'price_tolerance_percent' => 7]);
    matchingTolerance($this, ['name' => 'Fast', 'scope' => ToleranceScope::Tenant->value, 'priority' => 10, 'price_tolerance_percent' => 6]);

    expect(($this->resolver)()->pricePercent)->toBe('6.00');
});

it('restricts a rule to a match type and a currency', function (): void {
    matchingTolerance($this, ['name' => 'Services', 'scope' => ToleranceScope::Tenant->value, 'match_type' => '2_way', 'price_tolerance_percent' => 5]);
    matchingTolerance($this, ['name' => 'Swiss francs', 'scope' => ToleranceScope::Tenant->value, 'currency' => 'CHF', 'priority' => 50, 'price_tolerance_amount' => 7]);

    expect(($this->resolver)(null, null, InvoiceMatchType::TwoWay, 'EUR')->pricePercent)->toBe('5.00')
        ->and(($this->resolver)(null, null, InvoiceMatchType::ThreeWay, 'EUR')->pricePercent)->toBe('0.00')
        ->and(($this->resolver)(null, null, InvoiceMatchType::ThreeWay, 'chf')->priceAmount)->toBe('7.00')
        ->and(($this->resolver)(null, null, InvoiceMatchType::ThreeWay, 'EUR')->priceAmount)->toBe('0.00');
});

it('lets the quantity tolerance of a rule absorb a small over-delivery in the invoice', function (): void {
    matchingTolerance($this, ['name' => 'Bulk goods', 'scope' => ToleranceScope::Tenant->value, 'quantity_tolerance_percent' => 5]);
    $order = Fixtures::order($this->tenant, $this->supplier, [['Bulk gravel', 100, '10.00']]);
    Fixtures::receive($this->tenant, $order, [1 => 100], (string) $this->owner->id);

    $within = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [['Bulk gravel', 104, '10.00']]), (string) $order->id);
    $order2 = Fixtures::order($this->tenant, $this->supplier, [['Bulk sand', 100, '10.00']]);
    Fixtures::receive($this->tenant, $order2, [1 => 100], (string) $this->owner->id);
    $beyond = matchingRun($this, Fixtures::invoice($this->tenant, $this->supplier, [['Bulk sand', 106, '10.00']]), (string) $order2->id);

    expect($within->status->value)->toBe('within_tolerance')
        ->and($within->lines->first()->allowed_quantity_variance)->toBe('5.0000')
        ->and($beyond->status->value)->toBe('blocked_discrepancy')
        ->and($beyond->lines->first()->status->value)->toBe('quantity_variance');
});
