<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockSerialNumber;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->tenant->update(['vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'settings' => ['street' => 'Via Verdi 1', 'postal_code' => '20121', 'city' => 'Milano', 'province' => 'MI']]);
    fiscalCalendar($this->tenant, (int) now()->year);
    logisticsUser($this->tenant);
    $this->contact = Contact::create(['company_name' => 'Cliente Riparazioni', 'country' => 'IT', 'vat_number' => '01234567897']);
    $this->item = logisticsItem(['tracking_type' => 'serial', 'name' => 'Laptop']);
    $this->serial = StockSerialNumber::create(['stock_item_id' => $this->item->id, 'serial_number' => 'SN-200', 'status' => 'delivered', 'contact_id' => $this->contact->id]);
});

/**
 * Opens a repair, uses a spare part and finishes it with the given labour.
 *
 * @return array{id: string, quant: StockQuant, line: string}
 */
function finishedRepair(bool $underWarranty, ?string $salePrice = '40.00'): array
{
    $end = $underWarranty ? now()->addYear() : now()->subDay();
    test()->putJson('/api/v1/inventory/serials/'.test()->serial->id.'/warranty', [
        'contact_id' => test()->contact->id, 'purchase_date' => now()->subYears(2)->toDateString(), 'purchase_value' => '999.00', 'purchase_currency' => 'EUR',
        'warranty_start_date' => now()->subYears(2)->toDateString(), 'warranty_end_date' => $end->toDateString(),
    ])->assertOk();

    $id = test()->postJson('/api/v1/inventory/repair-orders', ['stock_serial_number_id' => test()->serial->id, 'fault_description' => 'Schermo rotto'])->assertCreated()->json('id');
    $product = $salePrice === null ? null : Product::create(['tenant_id' => test()->tenant->id, 'sku' => 'PRD-SCREEN', 'name' => 'Display 14', 'sale_price' => $salePrice, 'cost_price' => '12.50', 'tax_rate' => 22]);
    $part = logisticsItem(['tracking_type' => 'none', 'name' => 'Display 14', 'standard_cost' => 12.5, 'current_moving_average_cost' => 12.5, 'product_id' => $product?->id]);
    $bin = logisticsBin(logisticsWarehouse());
    $quant = logisticsQuant($bin, $part, '5');
    $line = test()->postJson("/api/v1/inventory/repair-orders/{$id}/parts", ['stock_item_id' => $part->id, 'quantity' => 2, 'source_bin_id' => $bin->id])->assertCreated()->json('id');

    foreach (['diagnosing', 'repairing'] as $status) {
        test()->postJson("/api/v1/inventory/repair-orders/{$id}/status", ['status' => $status])->assertOk();
    }

    test()->postJson("/api/v1/inventory/repair-orders/{$id}/status", ['status' => 'ready', 'labour_amount' => '85.00', 'resolution' => 'Display sostituito'])->assertOk();

    return ['id' => $id, 'quant' => $quant, 'line' => $line];
}

test('a repair outside warranty is invoiced once, labour and parts, without issuing the parts again', function (): void {
    $repair = finishedRepair(underWarranty: false);

    expect((string) $repair['quant']->fresh()->quantity_on_hand)->toBe('3.0000');

    $response = $this->postJson("/api/v1/inventory/repair-orders/{$repair['id']}/invoice", ['tax_rate' => 22])->assertCreated();
    $invoice = Invoice::query()->with('items')->findOrFail($response->json('invoice_id'));

    expect($invoice->status)->toBe('posted')
        ->and($invoice->contact_id)->toBe($this->contact->id)
        ->and($invoice->items)->toHaveCount(2)
        ->and((string) $invoice->total_net)->toBe('165.00')
        ->and((string) $invoice->total_gross)->toBe('201.30')
        ->and($invoice->gl_journal_entry_id)->not->toBeNull()
        ->and($response->json('repair_order.invoice_id'))->toBe($invoice->id)
        ->and((string) $repair['quant']->fresh()->quantity_on_hand)->toBe('3.0000')
        ->and(DB::table('stock_movements')->where('origin_document_id', $repair['id'])->count())->toBe(1);

    $this->postJson("/api/v1/inventory/repair-orders/{$repair['id']}/invoice", ['tax_rate' => 22])->assertOk()->assertJsonPath('invoice_id', $invoice->id);

    expect(Invoice::query()->where('contact_id', $this->contact->id)->count())->toBe(1);
});

test('a repair under warranty or not finished is not invoiced', function (): void {
    $covered = finishedRepair(underWarranty: true);

    $this->postJson("/api/v1/inventory/repair-orders/{$covered['id']}/invoice", ['tax_rate' => 22])->assertStatus(422);
    expect(Invoice::query()->count())->toBe(0);

    $this->serial->update(['status' => 'delivered']);
    $this->putJson('/api/v1/inventory/serials/'.$this->serial->id.'/warranty', [
        'contact_id' => $this->contact->id, 'purchase_date' => '2020-01-01', 'purchase_value' => '999.00', 'purchase_currency' => 'EUR', 'warranty_start_date' => '2020-01-01', 'warranty_end_date' => '2021-01-01',
    ])->assertOk();
    $open = $this->postJson('/api/v1/inventory/repair-orders', ['stock_serial_number_id' => $this->serial->id, 'fault_description' => 'Tastiera'])->assertCreated()->json('id');

    $this->postJson("/api/v1/inventory/repair-orders/{$open}/invoice", ['tax_rate' => 22])->assertStatus(422);
    $this->postJson("/api/v1/inventory/repair-orders/{$open}/invoice", [])->assertStatus(422)->assertJsonValidationErrors('tax_rate');
});

test('a part without a sale price needs one in the request', function (): void {
    $repair = finishedRepair(underWarranty: false, salePrice: null);

    $this->postJson("/api/v1/inventory/repair-orders/{$repair['id']}/invoice", ['tax_rate' => 22])->assertStatus(422);

    $this->postJson("/api/v1/inventory/repair-orders/{$repair['id']}/invoice", ['tax_rate' => 22, 'parts' => [['line_id' => $repair['line'], 'unit_price' => 30]]])
        ->assertCreated()->assertJsonPath('total_gross', '176.90');
});
