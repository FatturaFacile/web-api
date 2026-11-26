<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Workflow\Contracts\ApprovableDocument;
use Modules\Workflow\Services\ApprovableRegistry;
use Modules\Workflow\Services\WorkflowLifecycleService;
use Modules\Workflow\Support\FunctionalAmount;

uses(DatabaseTransactions::class);

/**
 * A tenant whose functional currency is the Swiss franc, active in the context.
 */
function chfTenant(): Tenant
{
    $tenant = Tenant::create(['id' => (string) Str::uuid7(), 'name' => 'Franc AG', 'legal_name' => 'Franc AG', 'status' => 'active', 'default_currency' => 'CHF']);
    app(TenantContext::class)->setTenant($tenant);

    return $tenant;
}

test('the registry knows every subject type of the specification', function (): void {
    $codes = array_column(app(ApprovableRegistry::class)->subjectTypes(), 'code');

    expect($codes)->toContain('sales_quote', 'purchase_request', 'purchase_order', 'supplier_invoice', 'expense_report', 'leave_request', 'payment_batch', 'supplier_master', 'dms_document');
});

test('legacy aliases resolve to the canonical subject type and to the model class', function (): void {
    $registry = app(ApprovableRegistry::class);

    expect($registry->resolve('purchase_requisition'))->toBe('purchase_request')
        ->and($registry->resolve('passive_invoice'))->toBe('supplier_invoice')
        ->and($registry->resolve(PurchaseOrder::class))->toBe('purchase_order')
        ->and($registry->classFor('purchase_order'))->toBe(PurchaseOrder::class)
        ->and($registry->moduleOf('expense_report'))->toBe('expense_management')
        ->and($registry->resolve('unknown_thing'))->toBeNull()
        ->and($registry->has('dms_document'))->toBeTrue();
});

test('an unregistered subject type has no class', function (): void {
    expect(fn () => app(ApprovableRegistry::class)->classFor('payment_batch'))->toThrow(InvalidArgumentException::class);
});

test('a subject can be registered at boot', function (): void {
    $registry = new ApprovableRegistry;
    $registry->register('payment_batch', ExpenseReport::class, 'treasury');

    expect($registry->classFor('payment_batch'))->toBe(ExpenseReport::class)
        ->and(collect($registry->subjectTypes())->firstWhere('code', 'payment_batch')['registered'])->toBeTrue();
});

test('the consumer documents adopt the approvable contract', function (): void {
    expect(new PurchaseOrder)->toBeInstanceOf(ApprovableDocument::class)
        ->and(new ExpenseReport)->toBeInstanceOf(ApprovableDocument::class)
        ->and((new PurchaseOrder)->approvalSubjectType())->toBe('purchase_order')
        ->and((new ExpenseReport)->approvalSubjectType())->toBe('expense_report');
});

test('a foreign currency amount is converted with the rate of the document', function (): void {
    chfTenant();

    expect(FunctionalAmount::of(1000.0, 'EUR', '1.0500'))->toBe(1050.0)
        ->and(FunctionalAmount::of(1000.0, 'CHF', '1.0500'))->toBe(1000.0)
        ->and(FunctionalAmount::of(1000.0, 'EUR', null))->toBe(1000.0)
        ->and(FunctionalAmount::of(1000.0, null, '1.05'))->toBe(1000.0)
        ->and(FunctionalAmount::of(1000.0, 'USD', '0.9', 'CHF'))->toBe(900.0);

    app(TenantContext::class)->clear();
});

test('the purchase order exposes its functional amount and the requester', function (): void {
    chfTenant();
    $creator = (string) Str::uuid();

    $order = (new PurchaseOrder)->forceFill(['total_amount' => '2000.00', 'currency' => 'EUR', 'exchange_rate' => '0.95', 'cost_center_id' => 'cc-1', 'created_by' => $creator]);

    expect($order->approvalAttributes())->toMatchArray(['amount' => 2000.0, 'amount_functional' => 1900.0, 'currency' => 'EUR', 'cost_center_id' => 'cc-1'])
        ->and($order->requesterId())->toBe($creator);

    app(TenantContext::class)->clear();
});

test('a key field change beyond the tolerance voids an approval and a small amount change does not', function (): void {
    config(['workflow.invalidation.amount_tolerance_percent' => 5.0]);
    $lifecycle = app(WorkflowLifecycleService::class);

    $approved = ['amount' => 1000.0, 'supplier_id' => 's1', 'cost_center_id' => 'c1', 'currency' => 'CHF'];

    expect($lifecycle->changedKeyFields($approved, [...$approved, 'amount' => 1050.0]))->toBe([])
        ->and($lifecycle->changedKeyFields($approved, [...$approved, 'amount' => 1050.01]))->toBe(['amount'])
        ->and($lifecycle->changedKeyFields($approved, [...$approved, 'amount' => 951.0]))->toBe([])
        ->and($lifecycle->changedKeyFields($approved, [...$approved, 'supplier_id' => 's2']))->toBe(['supplier_id'])
        ->and($lifecycle->changedKeyFields($approved, [...$approved, 'cost_center_id' => 'c2', 'currency' => 'EUR']))->toBe(['cost_center_id', 'currency'])
        ->and($lifecycle->changedKeyFields($approved, $approved))->toBe([]);
});

test('with no tolerance any change of the amount voids the approval', function (): void {
    config(['workflow.invalidation.amount_tolerance_percent' => 0.0]);
    $lifecycle = app(WorkflowLifecycleService::class);

    expect($lifecycle->changedKeyFields(['amount' => 100.0], ['amount' => 100.01]))->toBe(['amount'])
        ->and($lifecycle->changedKeyFields(['amount' => 0.0], ['amount' => 0.0]))->toBe([])
        ->and($lifecycle->changedKeyFields(['amount' => 0.0], ['amount' => 1.0]))->toBe(['amount']);
});
