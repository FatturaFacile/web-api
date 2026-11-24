<?php

declare(strict_types=1);

use Database\Factories\PaymentBatchFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\StockSerialNumber;
use Modules\Inventory\Models\Warehouse;
use Modules\Treasury\Models\BankAccount;

uses(RefreshDatabase::class);

/**
 * The tenant of the stock item a factory row points to.
 */
function legacyFactoryItemTenant(string $stockItemId): ?string
{
    return StockItem::withoutGlobalScopes()->whereKey($stockItemId)->value('tenant_id');
}

test('stock factories build rows on the real schema, all on one tenant', function (): void {
    $serial = StockSerialNumber::factory()->create();
    $lot = StockLot::factory()->create();
    $reservation = StockReservation::factory()->create();

    expect(legacyFactoryItemTenant($serial->stock_item_id))->toBe($serial->tenant_id)
        ->and(legacyFactoryItemTenant($lot->stock_item_id))->toBe($lot->tenant_id)
        ->and(legacyFactoryItemTenant($reservation->stock_item_id))->toBe($reservation->tenant_id)
        ->and(Warehouse::withoutGlobalScopes()->whereKey($reservation->warehouse_id)->value('tenant_id'))->toBe($reservation->tenant_id);
});

test('a payment batch uses a bank account of its own tenant', function (): void {
    $batch = PaymentBatchFactory::new()->create();

    expect(BankAccount::withoutGlobalScopes()->whereKey($batch->bank_account_id)->value('tenant_id'))->toBe($batch->tenant_id);
});
