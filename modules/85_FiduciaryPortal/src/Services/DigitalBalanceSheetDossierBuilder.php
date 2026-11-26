<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\FiduciaryPortal\Models\FiduciaryAuditLog;
use Modules\FiduciaryPortal\Models\FiduciaryEntryNote;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\LedgerReportService;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockValuationSnapshot;
use RuntimeException;
use ZipArchive;

/**
 * Digital Balance Sheet Dossier ("Fascicolo Digitale di Bilancio"):
 * Builds a structured ZIP archive with manifest/index and all accounting & compliance artifacts.
 */
final readonly class DigitalBalanceSheetDossierBuilder
{
    public function __construct(
        private LedgerReportService $ledgerReports,
        private FiduciaryTranscodingService $transcoding,
        private FiduciaryAuditService $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(string $tenantId, ?string $fiscalYearId = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        [$from, $to, $fiscalYear] = $this->resolveRange($tenantId, $fiscalYearId, $fromDate, $toDate);

        $journalEntriesCount = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['posted', 'reversed'])
            ->whereDate('posting_date', '>=', $from->toDateString())
            ->whereDate('posting_date', '<=', $to->toDateString())
            ->count();

        $verifiedEntriesCount = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['posted', 'reversed'])
            ->where('fiduciary_verified', true)
            ->whereDate('posting_date', '>=', $from->toDateString())
            ->whereDate('posting_date', '<=', $to->toDateString())
            ->count();

        $activeInvoicesCount = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->count();

        $passiveInvoicesCount = PassiveInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->count();

        $bankStatementsCount = BankStatement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('statement_date', '>=', $from->toDateString())
            ->whereDate('statement_date', '<=', $to->toDateString())
            ->count();

        $fixedAssetsCount = FixedAsset::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        $depreciationSchedulesCount = DepreciationSchedule::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        $inventoryItemsCount = StockItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        $inventorySnapshotsCount = StockValuationSnapshot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('snapshot_date', '>=', $from->toDateString())
            ->whereDate('snapshot_date', '<=', $to->toDateString())
            ->count();

        $notesCount = FiduciaryEntryNote::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        return [
            'fiscal_year_id' => $fiscalYear?->id ? (string) $fiscalYear->id : null,
            'year_code' => $fiscalYear?->year_code,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'datasets' => [
                'journal_entries' => $journalEntriesCount,
                'verified_journal_entries' => $verifiedEntriesCount,
                'active_invoices' => $activeInvoicesCount,
                'passive_invoices' => $passiveInvoicesCount,
                'bank_statements' => $bankStatementsCount,
                'fixed_assets' => $fixedAssetsCount,
                'depreciation_schedules' => $depreciationSchedulesCount,
                'inventory_items' => $inventoryItemsCount,
                'inventory_snapshots' => $inventorySnapshotsCount,
                'fiduciary_notes' => $notesCount,
            ],
        ];
    }

    /**
     * @return array{path: string, file_name: string, manifest: array<string, mixed>}
     */
    public function build(
        string $tenantId,
        ?string $fiscalYearId = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        string $studioSystem = 'universal',
        ?User $actor = null,
    ): array {
        $tenant = Tenant::query()->findOrFail($tenantId);
        [$from, $to, $fiscalYear] = $this->resolveRange($tenantId, $fiscalYearId, $fromDate, $toDate);
        $preview = $this->preview($tenantId, $fiscalYearId, $fromDate, $toDate);

        $zipPath = (string) tempnam(sys_get_temp_dir(), 'fiduciary-dossier-');
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create digital balance sheet dossier archive.');
        }

        $files = [];
        $addCsv = function (string $archivePath, array $headers, array $rows) use ($zip, &$files): void {
            $csv = $this->toCsv($headers, $rows);
            $zip->addFromString($archivePath, $csv);
            $files[] = [
                'path' => $archivePath,
                'rows' => count($rows),
                'sha256' => hash('sha256', $csv),
            ];
        };

        // 1. Journal Entries & Trial Balance
        $entries = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['posted', 'reversed'])
            ->whereDate('posting_date', '>=', $from->toDateString())
            ->whereDate('posting_date', '<=', $to->toDateString())
            ->with(['lines.account'])
            ->orderBy('posting_date')
            ->orderBy('entry_number')
            ->get();

        $transcodingMap = $this->transcoding->mapForTenant($tenantId, $studioSystem);
        $journalRows = [];
        /** @var JournalEntry $entry */
        foreach ($entries as $entry) {
            /** @var JournalLine $line */
            foreach ($entry->lines as $line) {
                $rule = $transcodingMap[(string) $line->account_id] ?? null;
                $journalRows[] = [
                    (string) $entry->entry_number,
                    $entry->posting_date?->toDateString() ?? '',
                    (string) $entry->status,
                    $entry->getAttribute('fiduciary_verified') ? 'VERIFIED' : 'UNVERIFIED',
                    (string) ($line->account?->account_code ?? ''),
                    (string) ($rule?->studio_account_code ?? ''),
                    (string) ($line->account?->name ?? ''),
                    (string) ($line->functional_debit ?? $line->debit_amount),
                    (string) ($line->functional_credit ?? $line->credit_amount),
                    (string) $line->currency,
                    (string) ($line->description ?: $entry->description),
                ];
            }
        }
        $addCsv(
            '01_prima_nota_and_trial_balance/libro_giornale.csv',
            ['EntryNumber', 'PostingDate', 'Status', 'FiduciaryStatus', 'AccountCode', 'StudioAccountCode', 'AccountName', 'Debit', 'Credit', 'Currency', 'Description'],
            $journalRows
        );

        $trialBalance = $this->ledgerReports->trialBalance($tenantId, $from, $to);
        $tbRows = array_map(static function (array $row) use ($transcodingMap): array {
            $rule = $transcodingMap[(string) $row['account_id']] ?? null;

            return [
                (string) $row['account_code'],
                (string) ($rule?->studio_account_code ?? ''),
                (string) $row['account_name'],
                (string) $row['account_type'],
                (string) $row['opening_balance'],
                (string) $row['debit'],
                (string) $row['credit'],
                (string) $row['closing_balance'],
            ];
        }, $trialBalance['accounts']);
        $addCsv(
            '01_prima_nota_and_trial_balance/bilancio_di_verifica.csv',
            ['AccountCode', 'StudioAccountCode', 'AccountName', 'Type', 'OpeningBalance', 'PeriodDebit', 'PeriodCredit', 'ClosingBalance'],
            $tbRows
        );

        // 2. Active Invoices (Fatture Attive)
        $activeInvoices = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('contact:id,company_name,first_name,last_name,vat_number')
            ->orderBy('date')
            ->get();
        $sdiXmlByInvoice = $activeInvoices->isEmpty()
            ? collect()
            : InvoiceSdiMetadata::query()
                ->whereIn('invoice_id', $activeInvoices->modelKeys())
                ->whereNotNull('xml_content')
                ->get()
                ->keyBy('invoice_id');

        $activeRows = [];
        foreach ($activeInvoices as $inv) {
            $sdi = $sdiXmlByInvoice->get($inv->id);
            $xmlName = null;
            if ($sdi !== null && is_string($sdi->xml_content) && $sdi->xml_content !== '') {
                $xmlName = '02_fatture_attive/xml/'.preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($sdi->file_name ?: $inv->invoice_number)).'.xml';
                $zip->addFromString($xmlName, $sdi->xml_content);
            }
            $counterparty = (string) ($inv->contact?->company_name ?: trim(($inv->contact?->first_name ?? '').' '.($inv->contact?->last_name ?? '')));
            $activeRows[] = [
                (string) $inv->invoice_number,
                $inv->date->toDateString(),
                $counterparty,
                (string) ($inv->contact?->vat_number ?? ''),
                (string) $inv->total_net,
                (string) $inv->total_tax,
                (string) $inv->total_gross,
                (string) $inv->currency,
                (string) $inv->payment_status,
                $xmlName ?? 'no_xml',
            ];
        }
        $addCsv(
            '02_fatture_attive/fatture_attive_index.csv',
            ['InvoiceNumber', 'Date', 'Customer', 'VatNumber', 'NetTotal', 'TaxTotal', 'GrossTotal', 'Currency', 'PaymentStatus', 'XmlFile'],
            $activeRows
        );

        // 3. Passive Invoices (Fatture Passive)
        $passiveInvoices = PassiveInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('supplier:id,company_name,first_name,last_name,vat_number')
            ->orderBy('date')
            ->get();

        $passiveRows = [];
        foreach ($passiveInvoices as $pinv) {
            $xmlName = null;
            if (is_string($pinv->xml_payload) && $pinv->xml_payload !== '') {
                $xmlName = '03_fatture_passive/xml/'.preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($pinv->sdi_file_name ?: $pinv->id)).'.xml';
                $zip->addFromString($xmlName, $pinv->xml_payload);
            }
            $supplierName = (string) ($pinv->supplier?->company_name ?: trim(($pinv->supplier?->first_name ?? '').' '.($pinv->supplier?->last_name ?? '')));
            $passiveRows[] = [
                (string) $pinv->invoice_number,
                $pinv->date->toDateString(),
                $supplierName,
                (string) ($pinv->supplier?->vat_number ?? ''),
                (string) $pinv->total_net,
                (string) $pinv->total_tax,
                (string) $pinv->total_amount,
                (string) $pinv->currency,
                (string) $pinv->approval_status,
                $xmlName ?? 'no_xml',
            ];
        }
        $addCsv(
            '03_fatture_passive/fatture_passive_index.csv',
            ['InvoiceNumber', 'Date', 'Supplier', 'VatNumber', 'NetTotal', 'TaxTotal', 'GrossTotal', 'Currency', 'ApprovalStatus', 'XmlFile'],
            $passiveRows
        );

        // 4. Bank Statements & Lines (Estratti Conto Bancari)
        $statements = BankStatement::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('statement_date', '>=', $from->toDateString())
            ->whereDate('statement_date', '<=', $to->toDateString())
            ->orderBy('statement_date')
            ->get();

        $statementRows = $statements->map(static fn (BankStatement $st): array => [
            (string) $st->id,
            (string) $st->source_format,
            (string) $st->account_iban,
            $st->statement_date?->toDateString() ?? '',
            (string) $st->opening_balance,
            (string) $st->closing_balance,
            (string) $st->currency,
            (string) $st->status,
        ])->values()->all();
        $addCsv(
            '04_estratti_conto_bancari/estratti_conto_summary.csv',
            ['StatementId', 'SourceFormat', 'AccountIBAN', 'StatementDate', 'OpeningBalance', 'ClosingBalance', 'Currency', 'Status'],
            $statementRows
        );

        $bankLines = $statements->isEmpty()
            ? collect()
            : BankStatementLine::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('bank_statement_id', $statements->modelKeys())
                ->orderBy('booking_date')
                ->get();
        $bankLineRows = $bankLines->map(static fn (BankStatementLine $l): array => [
            (string) $l->bank_statement_id,
            $l->booking_date?->toDateString() ?? '',
            $l->value_date?->toDateString() ?? '',
            (string) $l->direction,
            (string) $l->amount,
            (string) ($l->currency ?? ''),
            (string) ($l->counterpart_name ?? ''),
            (string) ($l->reference ?? ''),
            $l->reconciled ? '1' : '0',
            (string) ($l->description ?? ''),
        ])->values()->all();
        $addCsv(
            '04_estratti_conto_bancari/movimenti_bancari.csv',
            ['StatementId', 'BookingDate', 'ValueDate', 'Direction', 'Amount', 'Currency', 'Counterpart', 'Reference', 'Reconciled', 'Description'],
            $bankLineRows
        );

        // 5. Depreciation Schedules (Prospetti Ammortamenti)
        $schedules = DepreciationSchedule::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('asset:id,asset_number,name,acquisition_cost,carrying_amount')
            ->orderBy('period_code')
            ->get();
        $depreciationRows = $schedules->map(static fn (DepreciationSchedule $s): array => [
            (string) ($s->asset?->asset_number ?? ''),
            (string) ($s->asset?->name ?? ''),
            (string) ($s->period_code ?? ''),
            (string) ($s->depreciation_method ?? ''),
            (string) ($s->opening_book_value ?? '0.00'),
            (string) ($s->depreciation_amount ?? '0.00'),
            (string) ($s->accumulated_depreciation ?? '0.00'),
            (string) ($s->closing_book_value ?? '0.00'),
            (string) ($s->status ?? ''),
        ])->values()->all();
        $addCsv(
            '05_prospetti_ammortamenti/registro_cespiti_e_ammortamenti.csv',
            ['AssetNumber', 'AssetName', 'PeriodCode', 'Method', 'OpeningBookValue', 'DepreciationAmount', 'AccumulatedDepreciation', 'ClosingBookValue', 'Status'],
            $depreciationRows
        );

        // 6. Inventory Valuation Reports (Inventari di Magazzino)
        $snapshots = StockValuationSnapshot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('snapshot_date', '>=', $from->toDateString())
            ->whereDate('snapshot_date', '<=', $to->toDateString())
            ->with(['item.product:id,sku,name', 'warehouse:id,code,name'])
            ->orderBy('snapshot_date')
            ->get();

        $inventoryRows = $snapshots->map(static fn (StockValuationSnapshot $snap): array => [
            $snap->snapshot_date?->toDateString() ?? '',
            (string) ($snap->warehouse?->code ?? ''),
            (string) ($snap->item?->product?->sku ?? ''),
            (string) ($snap->item?->product?->name ?? ''),
            (string) $snap->quantity_on_hand,
            (string) $snap->unit_cost,
            (string) $snap->total_valuation,
        ])->values()->all();
        $addCsv(
            '06_inventari_di_magazzino/valorizzazione_magazzino.csv',
            ['SnapshotDate', 'WarehouseCode', 'SKU', 'ProductName', 'QuantityOnHand', 'UnitCost', 'TotalValuation'],
            $inventoryRows
        );

        // 7. Fiduciary Notes & Audit Log
        $notes = FiduciaryEntryNote::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('journalEntry:id,entry_number')
            ->orderByDesc('created_at')
            ->get();
        $noteRows = $notes->map(static fn (FiduciaryEntryNote $n): array => [
            (string) ($n->journalEntry?->entry_number ?? ''),
            (string) $n->author_name,
            (string) $n->author_role,
            (string) $n->status,
            (string) $n->body,
            $n->created_at?->toIso8601String() ?? '',
            $n->resolved_at?->toIso8601String() ?? '',
        ])->values()->all();
        $addCsv(
            '07_registro_note_e_audit/registro_note_fiduciario.csv',
            ['EntryNumber', 'AuthorName', 'AuthorRole', 'Status', 'Body', 'CreatedAt', 'ResolvedAt'],
            $noteRows
        );

        $auditLogs = FiduciaryAuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get();
        $auditRows = $auditLogs->map(static fn (FiduciaryAuditLog $a): array => [
            $a->created_at?->toIso8601String() ?? '',
            (string) ($a->actor_name ?? ''),
            (string) ($a->actor_role ?? ''),
            (string) $a->action,
            (string) ($a->target_reference ?? ''),
            (string) $a->summary,
        ])->values()->all();
        $addCsv(
            '07_registro_note_e_audit/pista_audit_fiduciario.csv',
            ['Timestamp', 'ActorName', 'ActorRole', 'Action', 'TargetReference', 'Summary'],
            $auditRows
        );

        // 8. Manifest
        $lockedPeriods = FiscalPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'locked_by_fiduciary')
            ->pluck('period_name')
            ->values()
            ->all();

        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $actor?->name,
            'tenant' => [
                'id' => (string) $tenant->id,
                'name' => (string) $tenant->name,
                'legal_name' => (string) ($tenant->legal_name ?: $tenant->name),
                'country' => (string) $tenant->country,
                'vat_number' => $tenant->vat_number,
                'uid_number' => $tenant->uid_number,
                'currency' => (string) $tenant->default_currency,
            ],
            'period' => [
                'fiscal_year_id' => $fiscalYear?->id ? (string) $fiscalYear->id : null,
                'year_code' => $fiscalYear?->year_code,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'fiduciary_locked_periods' => $lockedPeriods,
            ],
            'datasets' => $preview['datasets'],
            'files' => $files,
        ];

        $zip->addFromString('00_manifest/manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->close();

        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $tenant->name);
        $fileName = "fascicolo_bilancio_{$slug}_{$from->format('Ymd')}_{$to->format('Ymd')}.zip";

        $this->audit->record(
            tenantId: $tenantId,
            action: 'dossier_downloaded',
            summary: "Generated digital balance sheet dossier [{$fileName}].",
            actor: $actor,
            targetType: 'digital_balance_sheet_dossier',
            targetReference: $fileName,
            metadata: ['from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'datasets' => $preview['datasets']],
        );

        return [
            'path' => $zipPath,
            'file_name' => $fileName,
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?FiscalYear}
     */
    private function resolveRange(string $tenantId, ?string $fiscalYearId, ?string $fromDate, ?string $toDate): array
    {
        $fiscalYear = null;
        if ($fiscalYearId !== null && $fiscalYearId !== '') {
            $fiscalYear = FiscalYear::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($fiscalYearId);
        }

        if ($fromDate !== null && $toDate !== null && $fromDate !== '' && $toDate !== '') {
            return [CarbonImmutable::parse($fromDate), CarbonImmutable::parse($toDate), $fiscalYear];
        }

        if ($fiscalYear !== null && $fiscalYear->start_date !== null && $fiscalYear->end_date !== null) {
            return [CarbonImmutable::parse($fiscalYear->start_date), CarbonImmutable::parse($fiscalYear->end_date), $fiscalYear];
        }

        $now = CarbonImmutable::now();

        return [$now->startOfYear(), $now->endOfYear(), $fiscalYear];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function toCsv(array $headers, array $rows): string
    {
        $escape = static function (string $cell): string {
            $clean = str_replace(["\r", "\n"], ' ', $cell);

            return (str_contains($clean, ';') || str_contains($clean, '"'))
                ? '"'.str_replace('"', '""', $clean).'"'
                : $clean;
        };

        $lines = [implode(';', array_map($escape, $headers))];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map($escape, $row));
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }
}
