<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\Contact;
use App\Services\Sales\DocumentTotalsCalculator;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SDI\Enums\TipoDocumento;

/**
 * Read-only reports of the sales documents, built from issued (posted) documents only: drafts and proformas never
 * appear. The sales register takes the VAT of each document from the lines of its journal entry, so it shows exactly
 * what reached the ledger (the statutory VAT registers live in the General Ledger module). The receivables aging spreads
 * what is still open on each document over its installments and buckets them by days past due.
 */
final class SalesReportService
{
    /** Upper bounds (days past due) of the aging buckets; anything beyond the last one is in "over_90". */
    private const array BUCKETS = ['current' => 0, 'days_1_30' => 30, 'days_31_60' => 60, 'days_61_90' => 90];

    public function __construct(private readonly InvoiceInstallmentService $installments) {}

    /**
     * The register of the issued sales documents of a period: one row per document (credit notes with negative
     * amounts), its VAT per rate from the posted entry, and the totals per currency.
     *
     * @return array{from: string, to: string, documents: list<array<string, mixed>>, totals: list<array<string, mixed>>}
     */
    public function salesRegister(string $tenantId, string $from, string $to, ?string $documentType = null): array
    {
        $documents = SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('status', 'posted')
            ->where('invoice_type', '!=', 'proforma')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderBy('date')
            ->orderBy('fiscal_year')
            ->orderBy('sequential_number')
            ->get()
            ->filter(static fn (SalesInvoice $invoice): bool => $documentType === null || TipoDocumento::ofInvoice($invoice)->value === $documentType)
            ->values();

        $customers = Contact::withoutGlobalScopes()->whereKey($documents->pluck('contact_id')->unique()->all())->get()->keyBy('id');
        $vatLines = $this->vatLinesOf($documents);
        $rows = [];
        $totals = [];

        foreach ($documents as $document) {
            $sign = $document->isCreditNote() ? '-1' : '1';
            $signed = static fn (mixed $amount): string => Decimal::round2(Decimal::mul(DocumentTotalsCalculator::decimal($amount ?? '0'), $sign));
            $customer = $customers->get($document->contact_id);
            $currency = (string) ($document->currency ?? '');

            $row = [
                'invoice_id' => (string) $document->id,
                'invoice_number' => (string) $document->invoice_number,
                'date' => CarbonImmutable::parse($document->date)->toDateString(),
                'document_type' => TipoDocumento::ofInvoice($document)->value,
                'customer_id' => (string) $document->contact_id,
                'customer_name' => $customer === null ? null : trim((string) ($customer->company_name ?: trim($customer->first_name.' '.$customer->last_name))),
                'customer_vat_number' => $customer?->vat_number,
                'currency' => $currency,
                'exchange_rate' => (string) $document->exchange_rate,
                'total_net' => $signed($document->total_net),
                'total_tax' => $signed($document->total_tax),
                'total_gross' => $signed($document->total_gross),
                'journal_entry_id' => $document->gl_journal_entry_id,
                'vat' => array_values($vatLines[(string) $document->id] ?? []),
            ];
            $rows[] = $row;

            $totals[$currency] ??= ['currency' => $currency, 'documents' => 0, 'total_net' => '0.00', 'total_tax' => '0.00', 'total_gross' => '0.00'];
            $totals[$currency]['documents']++;

            foreach (['total_net', 'total_tax', 'total_gross'] as $field) {
                $totals[$currency][$field] = Decimal::round2(Decimal::add($totals[$currency][$field], $row[$field]));
            }
        }

        return ['from' => $from, 'to' => $to, 'documents' => $rows, 'totals' => array_values($totals)];
    }

    /**
     * What customers still owe on issued documents at a date, per customer and currency, in the buckets current,
     * 1-30, 31-60, 61-90 and over 90 days past due. Each document contributes the open amount of each of its installments
     * at the due date of that installment.
     *
     * @return array{as_of: string, customers: list<array<string, mixed>>, totals: list<array<string, mixed>>}
     */
    public function receivablesAging(string $tenantId, string $asOf, ?string $customerId = null): array
    {
        $date = CarbonImmutable::parse($asOf)->startOfDay();
        $documents = SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('status', 'posted')
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->whereDate('date', '<=', $date->toDateString())
            ->whereIn('payment_status', ['unpaid', 'partially_paid', 'overdue'])
            ->when($customerId !== null, static fn ($query) => $query->where('contact_id', $customerId))
            ->get()
            ->reject(static fn (SalesInvoice $invoice): bool => TipoDocumento::ofInvoice($invoice)->isSelfInvoice());

        $customers = Contact::withoutGlobalScopes()->whereKey($documents->pluck('contact_id')->unique()->all())->get()->keyBy('id');
        $groups = [];
        $totals = [];

        foreach ($documents as $document) {
            $currency = (string) ($document->currency ?? '');
            $key = $document->contact_id.'|'.$currency;
            $customer = $customers->get($document->contact_id);

            $groups[$key] ??= [
                'customer_id' => (string) $document->contact_id,
                'customer_name' => $customer === null ? null : trim((string) ($customer->company_name ?: trim($customer->first_name.' '.$customer->last_name))),
                'currency' => $currency,
                ...$this->emptyBuckets(),
                'total_open' => '0.00',
                'documents' => [],
            ];
            $totals[$currency] ??= ['currency' => $currency, ...$this->emptyBuckets(), 'total_open' => '0.00'];

            foreach ($this->installments->scheduleOf($document) as $installment) {
                if (! Decimal::isPositive($installment['open'])) {
                    continue;
                }

                $daysPastDue = $installment['due_date'] === null ? 0 : (int) max(0, CarbonImmutable::parse($installment['due_date'])->startOfDay()->diffInDays($date, false));
                $bucket = $this->bucketOf($daysPastDue);

                foreach ([&$groups[$key], &$totals[$currency]] as &$target) {
                    $target[$bucket] = Decimal::round2(Decimal::add($target[$bucket], $installment['open']));
                    $target['total_open'] = Decimal::round2(Decimal::add($target['total_open'], $installment['open']));
                }
                unset($target);

                $groups[$key]['documents'][] = [
                    'invoice_id' => (string) $document->id,
                    'invoice_number' => (string) $document->invoice_number,
                    'installment' => $installment['sequence'],
                    'due_date' => $installment['due_date'],
                    'open_amount' => $installment['open'],
                    'days_past_due' => $daysPastDue,
                    'bucket' => $bucket,
                ];
            }
        }

        $customersRows = array_values(array_filter($groups, static fn (array $group): bool => Decimal::isPositive($group['total_open'])));
        usort($customersRows, static fn (array $left, array $right): int => Decimal::compare($right['total_open'], $left['total_open']));

        return ['as_of' => $date->toDateString(), 'customers' => $customersRows, 'totals' => array_values(array_filter($totals, static fn (array $total): bool => Decimal::isPositive($total['total_open'])))];
    }

    /**
     * The VAT of each document per rate, from the VAT lines of its posted journal entries (invoice or credit note).
     *
     * @param  Collection<int, SalesInvoice>  $documents
     * @return array<string, array<string, array{rate: string, taxable: string, tax: string}>>
     */
    private function vatLinesOf(Collection $documents): array
    {
        if ($documents->isEmpty()) {
            return [];
        }

        $byDocument = [];
        $lines = JournalLine::withoutGlobalScopes()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.source_document_type', ['sales_invoice', 'credit_note'])
            ->whereIn('journal_entries.source_document_id', $documents->pluck('id')->all())
            ->where('journal_entries.status', 'posted')
            ->where(static fn ($query) => $query->whereNotNull('journal_lines.vat_rate_percent')->orWhere('journal_lines.vat_tax_amount', '!=', 0))
            ->get(['journal_entries.source_document_id', 'journal_lines.vat_rate_percent', 'journal_lines.vat_base_amount', 'journal_lines.vat_tax_amount', 'journal_lines.debit_amount']);
        $creditNotes = $documents->filter(static fn (SalesInvoice $invoice): bool => $invoice->isCreditNote())->pluck('id')->map(static fn (mixed $id): string => (string) $id)->flip();

        foreach ($lines as $line) {
            $documentId = (string) $line->getAttribute('source_document_id');
            $rate = Decimal::round2(DocumentTotalsCalculator::decimal($line->vat_rate_percent ?? '0'));
            $sign = $creditNotes->has($documentId) ? '-1' : '1';
            $tax = Decimal::mul(Decimal::abs(DocumentTotalsCalculator::decimal($line->vat_tax_amount)), $sign);
            $base = Decimal::mul(Decimal::abs(DocumentTotalsCalculator::decimal($line->vat_base_amount ?? '0')), $sign);
            $current = $byDocument[$documentId][$rate] ?? ['rate' => $rate, 'taxable' => '0.00', 'tax' => '0.00'];

            $byDocument[$documentId][$rate] = [
                'rate' => $rate,
                'taxable' => Decimal::round2(Decimal::add($current['taxable'], $base)),
                'tax' => Decimal::round2(Decimal::add($current['tax'], $tax)),
            ];
        }

        return $byDocument;
    }

    /**
     * @return array<string, string>
     */
    private function emptyBuckets(): array
    {
        return ['current' => '0.00', 'days_1_30' => '0.00', 'days_31_60' => '0.00', 'days_61_90' => '0.00', 'over_90' => '0.00'];
    }

    private function bucketOf(int $daysPastDue): string
    {
        foreach (self::BUCKETS as $bucket => $limit) {
            if ($daysPastDue <= $limit) {
                return $bucket;
            }
        }

        return 'over_90';
    }
}
