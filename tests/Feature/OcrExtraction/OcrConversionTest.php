<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Infrastructure\Services\OcrTenantRunner;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Services\RfqService;

require_once __DIR__.'/OcrTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake();
});

/**
 * An approved purchase order of the vendor with two lines, the first of which is partly received.
 *
 * @param  array<string, mixed>  $company
 * @return array{order: string, line1: string, line2: string}
 */
function ocrPurchaseOrder(array $company, string $number = 'PO-2026-77'): array
{
    $tenantId = (string) $company['tenant']->id;
    [$order, $line1, $line2] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
    $now = now();

    DB::table('purchase_orders')->insert(['id' => $order, 'tenant_id' => $tenantId, 'supplier_id' => $company['vendor'], 'order_number' => $number, 'po_number' => $number, 'date' => '2026-02-20', 'status' => 'sent', 'currency' => 'EUR', 'total_net' => 150, 'total_tax' => 33, 'total_gross' => 183, 'total_amount' => 183, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('purchase_order_lines')->insert([
        ['id' => $line1, 'tenant_id' => $tenantId, 'purchase_order_id' => $order, 'line_number' => 1, 'description' => 'Toner nero HP', 'quantity_ordered' => 2, 'quantity_received' => 0, 'unit_price' => 50, 'tax_rate' => 22, 'line_net_amount' => 100, 'line_tax_amount' => 22, 'line_total_amount' => 122, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $line2, 'tenant_id' => $tenantId, 'purchase_order_id' => $order, 'line_number' => 2, 'description' => 'Carta A4', 'quantity_ordered' => 10, 'quantity_received' => 0, 'unit_price' => 5, 'tax_rate' => 22, 'line_net_amount' => 50, 'line_tax_amount' => 11, 'line_total_amount' => 61, 'created_at' => $now, 'updated_at' => $now],
    ]);

    return ['order' => $order, 'line1' => $line1, 'line2' => $line2];
}

test('an expense receipt becomes an expense of the expense module', function (): void {
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);
    $lookup = (string) Str::uuid();
    $category = (string) Str::uuid();
    DB::table('lookup_categories')->insert(['id' => $lookup, 'code' => 'expense_category', 'name' => 'Expense category', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('lookup_items')->insert(['id' => $category, 'tenant_id' => $company['tenant']->id, 'category_id' => $lookup, 'code' => 'office', 'created_at' => now(), 'updated_at' => now()]);
    $job = ocrReadyJob($this, $company, 'FT-17');

    ocrConfirm($this, $job, ocrReviewed($job, $company, [], ['category' => 'expense_receipt', 'expense_category_id' => $category]))
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.document_category', 'expense_receipt')
        ->assertJsonPath('data.resulting_entity_type', 'expense');

    $expense = DB::table('expenses')->where('id', $job->refresh()->resulting_entity_id)->first();

    expect($expense)->not->toBeNull()
        ->and((float) $expense->amount_gross)->toBe(183.0)
        ->and((float) $expense->amount_net)->toBe(150.0)
        ->and($expense->merchant_name)->toBe('Muster AG')
        ->and($expense->contact_id)->toBe($company['vendor'])
        ->and($expense->category_id)->toBe($category)
        ->and(DB::table('dms_document_links')->where('document_id', $job->dms_document_id)->where('linkable_type', 'expense')->exists())->toBeTrue();

    $unknown = ocrReadyJob($this, $company, 'FT-18');
    ocrConfirm($this, $unknown, ocrReviewed($unknown, $company, [], ['category' => 'expense_receipt', 'expense_category_id' => (string) Str::uuid()]))->assertUnprocessable();
});

test('a transport document receives the goods against the purchase order', function (): void {
    $company = ocrCompany('IT');
    ocrActAs($company['tenant']);
    $po = ocrPurchaseOrder($company);
    $job = ocrReadyJob($this, $company, 'FT-17');
    $reviewed = ocrReviewed($job, $company, [0 => ['purchase_order_line_id' => $po['line1']]], ['category' => 'transport_ddt', 'purchase_order_ids' => [$po['order']]]);

    // The second line is not linked to a line of the order.
    ocrConfirm($this, $job, $reviewed)->assertUnprocessable();
    expect($job->refresh()->status)->toBe(ExtractionJobStatus::ReadyForReview);

    $reviewed['lines'][1]['purchase_order_line_id'] = $po['line2'];
    ocrConfirm($this, $job, $reviewed)->assertOk()->assertJsonPath('data.resulting_entity_type', 'purchase_receipt');

    $receipt = DB::table('purchase_receipts')->where('id', $job->refresh()->resulting_entity_id)->first();

    expect($receipt->purchase_order_id)->toBe($po['order'])
        ->and($receipt->supplier_delivery_note_ref)->toBe('FT-17')
        ->and((float) DB::table('purchase_order_lines')->where('id', $po['line1'])->value('quantity_received'))->toBe(2.0)
        ->and((float) DB::table('purchase_order_lines')->where('id', $po['line2'])->value('quantity_received'))->toBe(10.0)
        ->and(DB::table('purchase_order_lines')->where('id', $po['line1'])->value('quantity_received'))->not->toBeNull();
});

test('a vendor quote is recorded on the request for quotation it answers', function (): void {
    $company = ocrCompany('IT');
    $user = ocrActAs($company['tenant']);
    $tenantId = (string) $company['tenant']->id;
    $supplier = $company['vendor'];
    DB::table('suppliers')->insert(['id' => $supplier, 'tenant_id' => $tenantId, 'contact_id' => $company['vendor'], 'supplier_code' => 'S-'.Str::upper(Str::random(4)), 'company_name' => 'Muster AG', 'status' => 'approved', 'is_approved_vendor' => true, 'qualification_status' => 'qualified', 'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now()]);

    $rfq = app(OcrTenantRunner::class)->run($tenantId, function () use ($tenantId, $supplier, $user): Rfq {
        $service = app(RfqService::class);
        $rfq = $service->create($tenantId, [
            'title' => 'Office supplies', 'response_deadline' => now()->addDays(60)->toDateString(), 'currency' => 'EUR', 'supplier_ids' => [$supplier],
            'lines' => [['description' => 'Toner nero HP', 'quantity' => '2'], ['description' => 'Carta A4', 'quantity' => '10']],
        ], (string) $user->id);

        return $service->issue($rfq);
    });
    $rfqLines = $rfq->lines->sortBy('line_number')->values();

    $job = ocrReadyJob($this, $company, 'FT-17');
    $reviewed = ocrReviewed($job, $company, [0 => ['rfq_line_id' => $rfqLines[0]->id], 1 => ['rfq_line_id' => $rfqLines[1]->id]], ['category' => 'vendor_quote']);

    ocrConfirm($this, $job, $reviewed)->assertUnprocessable();
    ocrConfirm($this, $job, [...$reviewed, 'rfq_id' => $rfq->id])->assertOk()->assertJsonPath('data.resulting_entity_type', 'vendor_quote');

    $quote = DB::table('procurement_supplier_quotes')->where('id', $job->refresh()->resulting_entity_id)->first();

    expect($quote->rfq_id)->toBe($rfq->id)
        ->and($quote->supplier_id)->toBe($supplier)
        ->and($quote->quote_reference)->toBe('FT-17')
        ->and((float) $quote->total_amount)->toBe(150.0)
        ->and(DB::table('procurement_supplier_quote_lines')->where('supplier_quote_id', $quote->id)->count())->toBe(2);
});
