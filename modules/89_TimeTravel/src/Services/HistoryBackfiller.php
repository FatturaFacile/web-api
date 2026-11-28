<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Inventory\Models\StockMovement;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalDomainEventEnvelope;
use Modules\TimeTravel\Listeners\GeneralLedgerEventCapture;
use Modules\TimeTravel\Listeners\InventoryEventCapture;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Support\Amount;
use Modules\TimeTravel\Support\Instant;

/**
 * Loads the history that predates the event store: the records of the four domains that have no event yet are appended with their own
 * valid time and a recording time of now (the system learns them now, and says so in the metadata). Idempotent: an aggregate that already
 * has its event is skipped, so it can be run again after an import.
 */
final readonly class HistoryBackfiller
{
    public function __construct(private BiTemporalEventRecorder $recorder, private InventoryEventCapture $inventory) {}

    /**
     * @return array<string, int> events appended per aggregate type
     */
    public function backfill(string $tenantId): array
    {
        return [
            AggregateType::GeneralLedger->value => $this->generalLedger($tenantId),
            AggregateType::OpenItems->value => $this->openItems($tenantId),
            AggregateType::InventoryWms->value => $this->inventory($tenantId),
            AggregateType::FixedAssets->value => $this->fixedAssets($tenantId),
        ];
    }

    private function generalLedger(string $tenantId): int
    {
        $count = 0;

        JournalEntry::withoutGlobalScopes()->with('lines')
            ->where('tenant_id', $tenantId)->whereIn('status', ['posted', 'reversed'])
            ->orderBy('id')
            ->chunkById(200, function ($entries) use ($tenantId, &$count): void {
                foreach ($entries as $entry) {
                    if ($this->recorder->exists($tenantId, AggregateType::GeneralLedger, (string) $entry->id, GeneralLedgerEventCapture::EVENT_TYPES)) {
                        continue;
                    }

                    $this->recorder->record(GeneralLedgerEventCapture::envelope($entry, 'JournalEntryPosted', [], null, ['backfilled' => true]));
                    $count++;
                }
            });

        return $count;
    }

    private function openItems(string $tenantId): int
    {
        $count = 0;

        Invoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNotNull('posted_at')->orderBy('id')
            ->chunkById(200, function ($invoices) use ($tenantId, &$count): void {
                foreach ($invoices as $invoice) {
                    $id = (string) $invoice->id;

                    if ($this->recorder->exists($tenantId, AggregateType::OpenItems, $id, ['InvoiceIssued'])) {
                        continue;
                    }

                    $date = substr((string) $invoice->date, 0, 10);
                    $this->append($tenantId, $id, 'InvoiceIssued', Instant::startOfDay($date), [
                        'document_id' => $id,
                        'document_number' => (string) $invoice->invoice_number,
                        'partner_id' => (string) $invoice->customer_id,
                        'partner_type' => 'customer',
                        'issue_date' => $date,
                        'due_date' => $invoice->due_date !== null ? substr((string) $invoice->due_date, 0, 10) : null,
                        'currency' => (string) $invoice->currency,
                        'gross' => Amount::of($invoice->total_gross),
                    ]);
                    $count++;

                    if (Amount::cmp($invoice->total_paid, 0) > 0) {
                        // The payment date was not kept as an event: the books learn it now.
                        $this->append($tenantId, $id, 'PaymentReconciled', Instant::now()->startOfDay(), [
                            'document_id' => $id,
                            'partner_id' => (string) $invoice->customer_id,
                            'partner_type' => 'customer',
                            'paid_amount' => Amount::of($invoice->total_paid),
                            'paid_at' => Instant::now()->toDateString(),
                        ], ['valid_at_estimated' => true]);
                        $count++;
                    }
                }
            });

        $supplierDocuments = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('source_document_type', ['vendor_invoice', 'vendor_credit_note'])->where('status', '!=', 'draft')
            ->distinct()->pluck('source_document_id');

        foreach ($supplierDocuments->chunk(200) as $ids) {
            foreach (PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $ids->all())->get() as $invoice) {
                $id = (string) $invoice->id;

                if ($this->recorder->exists($tenantId, AggregateType::OpenItems, $id, ['InvoiceReceived'])) {
                    continue;
                }

                $date = substr((string) $invoice->date, 0, 10);
                $gross = Amount::of(abs((float) $invoice->total_amount));
                $this->append($tenantId, $id, 'InvoiceReceived', Instant::startOfDay($date), [
                    'document_id' => $id,
                    'document_number' => (string) $invoice->invoice_number,
                    'partner_id' => (string) $invoice->supplier_id,
                    'partner_type' => 'supplier',
                    'issue_date' => $date,
                    'due_date' => $invoice->due_date !== null ? substr((string) $invoice->due_date, 0, 10) : null,
                    'currency' => (string) $invoice->currency,
                    'gross' => $invoice->document_type === 'TD04' ? Amount::neg($gross) : $gross,
                ]);
                $count++;
            }
        }

        return $count;
    }

    private function inventory(string $tenantId): int
    {
        $count = 0;

        StockMovement::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNotNull('stock_item_id')->orderBy('id')
            ->chunkById(200, function ($movements) use ($tenantId, &$count): void {
                foreach ($movements as $movement) {
                    $before = $this->recorder->exists($tenantId, AggregateType::InventoryWms, (string) $movement->id, ['InventoryLotMoved']);

                    if ($before) {
                        continue;
                    }

                    $this->inventory->onStockTransferred(new StockTransferredEvent(
                        movementId: (string) $movement->id,
                        stockItemId: (string) $movement->stock_item_id,
                        sourceBinId: $movement->source_location_id !== null ? (string) $movement->source_location_id : null,
                        destinationBinId: $movement->destination_location_id !== null ? (string) $movement->destination_location_id : null,
                        quantity: (float) $movement->quantity,
                        movementType: (string) $movement->movement_type,
                        totalValuation: (float) $movement->total_valuation,
                        tenantId: $tenantId,
                        companyId: (string) ($movement->company_id ?? ''),
                    ));

                    if ($this->recorder->exists($tenantId, AggregateType::InventoryWms, (string) $movement->id, ['InventoryLotMoved'])) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function fixedAssets(string $tenantId): int
    {
        $count = 0;

        FixedAsset::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')
            ->chunkById(200, function ($assets) use ($tenantId, &$count): void {
                foreach ($assets as $asset) {
                    $id = (string) $asset->id;

                    if ($this->recorder->exists($tenantId, AggregateType::FixedAssets, $id, ['AssetAcquired'])) {
                        continue;
                    }

                    $date = $asset->acquisition_date !== null ? substr((string) $asset->acquisition_date, 0, 10) : substr((string) $asset->created_at, 0, 10);
                    $this->append($tenantId, $id, 'AssetAcquired', Instant::startOfDay($date), [
                        'asset_id' => $id,
                        'asset_number' => (string) $asset->asset_number,
                        'name' => (string) $asset->name,
                        'category_id' => (string) $asset->category_id,
                        'acquisition_type' => (string) $asset->acquisition_type,
                        'cost' => Amount::of($asset->acquisition_cost),
                    ], [], AggregateType::FixedAssets);
                    $count++;
                }
            });

        DepreciationSchedule::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'posted')->orderBy('id')
            ->chunkById(200, function ($schedules) use ($tenantId, &$count): void {
                foreach ($schedules as $schedule) {
                    $assetId = (string) $schedule->asset_id;
                    $already = BiTemporalEvent::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)->where('stream_id', AggregateType::FixedAssets->streamId())
                        ->where('aggregate_id', $assetId)->where('event_type', 'AssetDepreciated')
                        ->get(['payload'])->contains(static fn ($event): bool => ($event->payload['schedule_id'] ?? null) === (string) $schedule->id);

                    if ($already) {
                        continue;
                    }

                    $postingDate = $schedule->journal_entry_id !== null
                        ? JournalEntry::withoutGlobalScopes()->whereKey($schedule->journal_entry_id)->value('posting_date')
                        : null;
                    $date = $postingDate !== null ? substr((string) $postingDate, 0, 10) : substr((string) ($schedule->posted_at ?? $schedule->created_at), 0, 10);

                    $this->append($tenantId, $assetId, 'AssetDepreciated', Instant::startOfDay($date), [
                        'asset_id' => $assetId,
                        'schedule_id' => (string) $schedule->id,
                        'journal_entry_id' => $schedule->journal_entry_id !== null ? (string) $schedule->journal_entry_id : null,
                        'period_code' => (string) $schedule->period_code,
                        'depreciation_amount' => Amount::of($schedule->depreciation_amount),
                        'new_carrying_amount' => Amount::of($schedule->closing_book_value),
                    ], [], AggregateType::FixedAssets);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    private function append(string $tenantId, string $aggregateId, string $eventType, CarbonImmutable $validAt, array $payload, array $metadata = [], AggregateType $type = AggregateType::OpenItems): void
    {
        $this->recorder->record(new BiTemporalDomainEventEnvelope($tenantId, $type, $aggregateId, $eventType, $validAt, $payload, ['backfilled' => true, ...$metadata]));
    }
}
