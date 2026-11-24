<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use Modules\Procurement\Enums\MatchTrigger;
use Modules\Procurement\Enums\ToleranceScope;
use Modules\Procurement\Models\InvoiceMatchingRun;
use Modules\Procurement\Models\InvoiceMatchToleranceRule;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Services\InvoiceMatchingService;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Builders of the invoice matching tests (Mod 048): two companies with an open fiscal calendar (treasury support), an
 * owner signed in on the first one and a supplier.
 */
function matchingSetUp(object $test): void
{
    treasurySetUp($test);
    $test->owner = treasuryActor($test, 'owner');
    $test->supplier = Fixtures::supplier($test->tenant);
    $test->matching = app(InvoiceMatchingService::class);
    $test->poLines = [['Industrial Fan Unit', 10, '100.00'], ['Mounting Kit', 5, '20.00']];
}

/**
 * An order of the two default lines with everything received.
 */
function matchingReceivedOrder(object $test): PurchaseOrder
{
    $order = Fixtures::order($test->tenant, $test->supplier, $test->poLines);
    Fixtures::receive($test->tenant, $order, [1 => 10, 2 => 5], (string) $test->owner->id);

    return $order;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function matchingTolerance(object $test, array $attributes): InvoiceMatchToleranceRule
{
    return Fixtures::in($test->tenant, fn (): InvoiceMatchToleranceRule => InvoiceMatchToleranceRule::create($attributes + [
        'tenant_id' => $test->tenant->id,
        'name' => 'Rule',
        'scope' => ToleranceScope::Tenant->value,
        'priority' => 100,
        'price_tolerance_percent' => 0,
        'price_tolerance_amount' => 0,
        'quantity_tolerance_percent' => 0,
        'quantity_tolerance_amount' => 0,
        'is_active' => true,
    ]));
}

function matchingRun(object $test, PassiveInvoice $invoice, ?string $orderId = null, MatchTrigger $trigger = MatchTrigger::Manual): InvoiceMatchingRun
{
    return Fixtures::in($test->tenant, fn () => $test->matching->run((string) $invoice->id, $orderId, $trigger, (string) $test->owner->id));
}

/**
 * An invoice of the default order lines with the fan unit billed at the given price, matched against the order.
 */
function matchingBlockedRun(object $test, ?PurchaseOrder $order = null, string $fanPrice = '110.00'): InvoiceMatchingRun
{
    $order ??= matchingReceivedOrder($test);
    $invoice = Fixtures::invoice($test->tenant, $test->supplier, [['Industrial Fan Unit', 10, $fanPrice], ['Mounting Kit', 5, '20.00']]);

    return matchingRun($test, $invoice, (string) $order->id);
}
