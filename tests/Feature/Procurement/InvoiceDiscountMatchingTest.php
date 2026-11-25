<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InvoiceMatchingFixtures as Fixtures;

require_once __DIR__.'/InvoiceMatchingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    matchingSetUp($this);
});

/**
 * An invoice whose first line states the list price 110.00 but a 10% line discount: 1000.00 for 10 units.
 */
function discountedInvoice(object $test, string $lineTotal): PassiveInvoice
{
    $invoice = Fixtures::invoice($test->tenant, $test->supplier, [['Industrial Fan Unit', 10, '110.00'], ['Mounting Kit', 5, '20.00']]);
    $net = bcadd($lineTotal, '100.00', 2);

    Fixtures::in($test->tenant, function () use ($invoice, $lineTotal, $net): void {
        $invoice->lines()->where('line_number', 1)->update(['line_total' => $lineTotal]);
        $invoice->update(['total_net' => $net, 'total_amount' => $net]);
    });

    return $invoice->refresh();
}

it('matches a discounted line at the price actually invoiced, not at the list price', function (): void {
    $order = matchingReceivedOrder($this);

    $run = matchingRun($this, discountedInvoice($this, '1000.00'), (string) $order->id);

    expect($run->status->isBlocking())->toBeFalse();
});

it('still blocks a line whose discounted price is above the order price', function (): void {
    $order = matchingReceivedOrder($this);

    // 1050.00 for 10 units is 105.00 each against 100.00 ordered.
    $run = matchingRun($this, discountedInvoice($this, '1050.00'), (string) $order->id);

    expect($run->status->isBlocking())->toBeTrue();
});

it('treats a rounding gap of one cent between price and total as no discount', function (): void {
    $order = matchingReceivedOrder($this);

    $run = matchingRun($this, discountedInvoice($this, '1000.00'), (string) $order->id);
    $invoice = Fixtures::invoice($this->tenant, $this->supplier, $this->poLines);

    expect(matchingRun($this, $invoice, (string) $order->id)->status->isBlocking())->toBeFalse()
        ->and($run->lines->count())->toBe(2);
});
