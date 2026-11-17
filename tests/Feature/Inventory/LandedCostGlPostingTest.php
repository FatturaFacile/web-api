<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Inventory\Events\LandedCostAppliedEvent;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\LandedCostAllocation;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Models\StockItem;
use Tests\Support\InventoryValuationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = Fx::actingTenantUser();
    $this->tenantId = (string) $this->tenant->id;
    ['warehouse' => $this->warehouse, 'bin' => $this->bin] = Fx::warehouseWithBin($this->tenantId);
});

/**
 * Receipt 10 @ 5.00, 4 units sold, then a 50.00 landed cost draft on that receipt.
 *
 * @return array{item: StockItem, receipt_id: string, document_id: string}
 */
function partlySoldReceiptWithLandedCost(object $test, string $method, array $itemAttributes = []): array
{
    $receipt = Fx::purchaseReceipt($test->tenantId);
    $item = Fx::item($test->tenantId, $method, $itemAttributes);
    Fx::receive($item, $test->bin, 10, 5.00, (string) $receipt->id);
    Fx::issue($item, $test->bin, 4);

    $documentId = $test->postJson('/api/v1/inventory/landed-costs', [
        'document_date' => now()->toDateString(),
        'cost_type' => 'freight',
        'allocation_key' => 'value',
        'currency' => 'EUR',
        'total_amount' => 50.00,
        'receipt_ids' => [(string) $receipt->id],
    ])->assertCreated()->json('id');

    return ['item' => $item, 'receipt_id' => (string) $receipt->id, 'document_id' => (string) $documentId];
}

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function journalLinesByAccountCode(JournalEntry $journal): array
{
    $codes = ChartOfAccount::query()->whereIn('id', $journal->lines->pluck('account_id'))->pluck('account_code', 'id');
    $result = [];
    foreach ($journal->lines as $line) {
        $code = (string) $codes[$line->account_id];
        $result[$code] = [
            'debit' => round(($result[$code]['debit'] ?? 0.0) + (float) $line->debit_amount, 2),
            'credit' => round(($result[$code]['credit'] ?? 0.0) + (float) $line->credit_amount, 2),
        ];
    }

    return $result;
}

test('applying a landed cost on a partly sold FIFO receipt splits inventory and COGS and posts a balanced journal', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    ['item' => $item, 'document_id' => $documentId] = partlySoldReceiptWithLandedCost($this, 'fifo');

    $response = $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply");

    $response->assertOk()
        ->assertJsonPath('status', 'applied')
        ->assertJsonPath('revaluation.total_inventory_delta', '30.00')
        ->assertJsonPath('revaluation.total_cogs_delta', '20.00')
        ->assertJsonPath('allocations.0.to_inventory_amount', '30.00')
        ->assertJsonPath('allocations.0.to_cogs_amount', '20.00');

    $journalId = $response->json('journal_entry_id');
    expect($journalId)->not->toBeNull()
        ->and($response->json('revaluation.journal_entry_id'))->toBe($journalId);

    $journal = JournalEntry::query()->findOrFail($journalId);
    expect($journal->source_document_type)->toBe('landed_cost')
        ->and($journal->source_document_id)->toBe($documentId)
        ->and($journal->isBalanced())->toBeTrue()
        ->and(journalLinesByAccountCode($journal))->toBe([
            '1200' => ['debit' => 30.0, 'credit' => 0.0],
            '5000' => ['debit' => 20.0, 'credit' => 0.0],
            '2150' => ['debit' => 0.0, 'credit' => 50.0],
        ]);

    // Remaining 6 units carry +5.00 each; the next issue is costed at 10.00.
    $layer = InventoryCostLayer::query()->where('stock_item_id', $item->id)->where('source_type', 'receipt')->firstOrFail();
    expect((string) $layer->landed_cost_unit)->toBe('5.0000')
        ->and($layer->effectiveUnitCost())->toBe('10.0000')
        ->and(LandedCostAllocation::query()->where('landed_cost_document_id', $documentId)->count())->toBe(1);

    $issue = Fx::issue($item, $this->bin, 6);
    expect((string) $issue->total_valuation)->toBe('60.00');
});

test('applying a landed cost twice returns 409', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    ['document_id' => $documentId] = partlySoldReceiptWithLandedCost($this, 'fifo');

    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")->assertOk();
    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")->assertStatus(409);

    expect(JournalEntry::query()->where('source_document_type', 'landed_cost')->count())->toBe(1);
});

test('applying a landed cost in a closed period returns 422 and changes nothing', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'), 'open', 'closed');
    ['item' => $item, 'document_id' => $documentId] = partlySoldReceiptWithLandedCost($this, 'fifo');

    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_date']);

    expect(LandedCostDocument::query()->findOrFail($documentId)->status)->toBe('draft')
        ->and(LandedCostAllocation::count())->toBe(0)
        ->and((string) InventoryCostLayer::query()->where('stock_item_id', $item->id)->where('source_type', 'receipt')->firstOrFail()->landed_cost_unit)->toBe('0.0000');
});

test('landed cost on a weighted average item uplifts the average cost of the remaining stock', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    ['item' => $item, 'document_id' => $documentId] = partlySoldReceiptWithLandedCost($this, 'weighted_average');

    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")
        ->assertOk()
        ->assertJsonPath('revaluation.total_inventory_delta', '30.00')
        ->assertJsonPath('revaluation.total_cogs_delta', '20.00');

    // (6 * 5.00 + 30.00) / 6 = 10.0000
    expect((string) $item->refresh()->current_moving_average_cost)->toBe('10.0000');
});

test('landed cost on a standard cost item keeps inventory at standard and books the unsold share as variance', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    ['document_id' => $documentId] = partlySoldReceiptWithLandedCost($this, 'standard_cost', ['standard_cost' => 5.00]);

    $response = $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")
        ->assertOk()
        ->assertJsonPath('allocations.0.to_inventory_amount', '0.00')
        ->assertJsonPath('allocations.0.to_cogs_amount', '20.00')
        ->assertJsonPath('allocations.0.to_variance_amount', '30.00');

    $journal = JournalEntry::query()->findOrFail($response->json('journal_entry_id'));
    expect(journalLinesByAccountCode($journal))->toBe([
        '5000' => ['debit' => 20.0, 'credit' => 0.0],
        '5110' => ['debit' => 30.0, 'credit' => 0.0],
        '2150' => ['debit' => 0.0, 'credit' => 50.0],
    ]);
});

test('reversing an applied landed cost creates a reversal document, a mirror journal and restores the layer cost', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    ['item' => $item, 'document_id' => $documentId] = partlySoldReceiptWithLandedCost($this, 'fifo');
    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")->assertOk();

    $response = $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/reverse", ['reason' => 'Wrong freight invoice']);

    $response->assertOk()
        ->assertJsonPath('reversal_of_id', $documentId)
        ->assertJsonPath('status', 'applied')
        ->assertJsonPath('total_amount', '-50.00')
        ->assertJsonPath('allocations.0.to_inventory_amount', '-30.00')
        ->assertJsonPath('allocations.0.to_cogs_amount', '-20.00');

    $original = LandedCostDocument::query()->findOrFail($documentId);
    expect($original->status)->toBe('reversed')
        ->and($original->reversal_reason)->toBe('Wrong freight invoice');

    $reversalJournal = JournalEntry::query()->findOrFail($response->json('journal_entry_id'));
    expect($reversalJournal->source_document_id)->toBe($response->json('id'))
        ->and($reversalJournal->journal_type)->toBe('reversing')
        ->and($reversalJournal->isBalanced())->toBeTrue()
        ->and(journalLinesByAccountCode($reversalJournal))->toBe([
            '1200' => ['debit' => 0.0, 'credit' => 30.0],
            '5000' => ['debit' => 0.0, 'credit' => 20.0],
            '2150' => ['debit' => 50.0, 'credit' => 0.0],
        ]);

    $layer = InventoryCostLayer::query()->where('stock_item_id', $item->id)->where('source_type', 'receipt')->firstOrFail();
    expect((string) $layer->landed_cost_unit)->toBe('0.0000');

    // Posted documents are immutable: neither the original nor the reversal can be reversed again.
    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/reverse", ['reason' => 'again'])->assertStatus(409);
    $this->postJson('/api/v1/inventory/landed-costs/'.$response->json('id').'/reverse', ['reason' => 'again'])->assertStatus(409);
});

test('reversing a draft landed cost returns 409', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    ['document_id' => $documentId] = partlySoldReceiptWithLandedCost($this, 'fifo');

    $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/reverse", ['reason' => 'Not applied yet'])->assertStatus(409);
});

test('the GL listener is idempotent per landed cost document', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    $documentId = (string) Str::uuid7();
    $legs = [
        ['role' => 'inventory', 'account_id' => null, 'debit' => '30.00', 'credit' => '0.00', 'description' => 'inventory'],
        ['role' => 'landed_cost_clearing', 'account_id' => null, 'debit' => '0.00', 'credit' => '30.00', 'description' => 'clearing'],
    ];
    $event = new LandedCostAppliedEvent(
        landedCostDocumentId: $documentId,
        documentNumber: 'LC-TEST',
        revaluationId: (string) Str::uuid7(),
        postingDate: now()->toDateString(),
        totalAmount: '30.00',
        legs: $legs,
        isReversal: false,
        tenantId: $this->tenantId,
        companyId: $this->tenantId,
    );

    event($event);
    event($event);

    expect(JournalEntry::query()->where('source_document_type', 'landed_cost')->where('source_document_id', $documentId)->count())->toBe(1);
});

test('category GL account overrides are used for landed cost postings', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    $clearing = ChartOfAccount::create([
        'tenant_id' => $this->tenantId,
        'account_code' => '2160',
        'name' => 'Customs clearing',
        'account_class' => 'liability',
        'account_type' => 'liability',
        'normal_balance' => 'credit',
        'is_active' => true,
    ]);
    ['item' => $item, 'document_id' => $documentId] = partlySoldReceiptWithLandedCost($this, 'fifo');
    $item->category->forceFill(['landed_cost_clearing_account_id' => $clearing->id])->save();

    $journalId = $this->postJson("/api/v1/inventory/landed-costs/{$documentId}/apply")->assertOk()->json('journal_entry_id');

    expect(journalLinesByAccountCode(JournalEntry::query()->findOrFail($journalId)))->toHaveKey('2160')
        ->and(journalLinesByAccountCode(JournalEntry::query()->findOrFail($journalId)))->not->toHaveKey('2150');
});
