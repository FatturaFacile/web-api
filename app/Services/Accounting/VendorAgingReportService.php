<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;

/**
 * Vendor aging (scadenzario fornitori): the open balance of the received supplier invoices of the tenant, per supplier
 * and per due-date bucket relative to a reference date. Credit notes are not payables and settled invoices have no
 * balance; the balance is the invoice total less what has been imputed to the supply (bank fees do not count). Amounts
 * are never added across currencies: every supplier row and every summary has its own currency.
 */
class VendorAgingReportService
{
    public const BUCKETS = ['overdue', 'days_0_30', 'days_31_60', 'days_61_90', 'days_over_90'];

    /**
     * @return array{as_of_date: string, suppliers: array<int, array<string, mixed>>, summary: array<int, array<string, mixed>>}
     */
    public function report(CarbonImmutable $asOf, ?string $supplierId = null): array
    {
        $invoices = PassiveInvoice::query()->with('supplier')
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->where('document_type', '!=', 'TD04')
            ->when($supplierId !== null, fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderBy('due_date')->orderBy('invoice_number')
            ->get();

        $rows = [];

        foreach ($invoices as $invoice) {
            $balance = (int) round(((float) $invoice->total_amount - (float) $invoice->total_paid) * 100);

            if ($balance <= 0) {
                continue;
            }

            $due = CarbonImmutable::parse($invoice->due_date ?? $invoice->date)->startOfDay();
            $days = (int) $asOf->startOfDay()->diffInDays($due, false);
            $bucket = $this->bucket($days);
            $key = "{$invoice->supplier_id}|{$invoice->currency}";

            $rows[$key] ??= $this->emptyRow($invoice->supplier, (string) $invoice->supplier_id, (string) $invoice->currency);
            $rows[$key]['buckets'][$bucket] += $balance;
            $rows[$key]['total'] += $balance;

            if ($bucket === 'overdue') {
                $rows[$key]['overdue_days'][] = -$days;
            }

            $rows[$key]['invoices'][] = [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'due_date' => $due->toDateString(),
                'bucket' => $bucket,
                'days_overdue' => max(0, -$days),
                'balance' => $balance / 100,
            ];
        }

        uasort($rows, fn (array $a, array $b): int => [$a['currency'], -$a['total']] <=> [$b['currency'], -$b['total']]);

        return [
            'as_of_date' => $asOf->toDateString(),
            'suppliers' => array_values(array_map(fn (array $row): array => $this->present($row), $rows)),
            'summary' => $this->summarize($rows),
        ];
    }

    /**
     * The bucket of an invoice that falls due in `$days` days (negative when it is overdue).
     */
    private function bucket(int $days): string
    {
        return match (true) {
            $days < 0 => 'overdue',
            $days <= 30 => 'days_0_30',
            $days <= 60 => 'days_31_60',
            $days <= 90 => 'days_61_90',
            default => 'days_over_90',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(?Contact $supplier, string $supplierId, string $currency): array
    {
        return [
            'supplier_id' => $supplierId,
            'supplier_name' => $supplier?->company_name ?: ($supplier === null ? null : (trim($supplier->first_name.' '.$supplier->last_name) ?: null)),
            'tax_id' => $supplier?->vat_number ?: ($supplier?->fiscal_code ?: null),
            'currency' => $currency,
            'buckets' => array_fill_keys(self::BUCKETS, 0),
            'total' => 0,
            'overdue_days' => [],
            'invoices' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'supplier_id' => $row['supplier_id'],
            'supplier_name' => $row['supplier_name'],
            'tax_id' => $row['tax_id'],
            'currency' => $row['currency'],
            ...array_map(fn (int $cents): float => $cents / 100, $row['buckets']),
            'total' => $row['total'] / 100,
            'overdue_invoices' => count($row['overdue_days']),
            'average_days_overdue' => $row['overdue_days'] === [] ? 0.0 : round(array_sum($row['overdue_days']) / count($row['overdue_days']), 1),
            'invoices' => $row['invoices'],
        ];
    }

    /**
     * One summary per currency, with the cash outflow expected within 30, 60 and 90 days (overdue amounts included, as
     * they are due now).
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function summarize(array $rows): array
    {
        $summaries = [];

        foreach ($rows as $row) {
            $summary = &$summaries[$row['currency']];
            $summary ??= ['currency' => $row['currency'], 'buckets' => array_fill_keys(self::BUCKETS, 0), 'total' => 0, 'suppliers' => 0, 'overdue_days' => []];

            foreach ($row['buckets'] as $bucket => $cents) {
                $summary['buckets'][$bucket] += $cents;
            }

            $summary['total'] += $row['total'];
            $summary['suppliers']++;
            array_push($summary['overdue_days'], ...$row['overdue_days']);
            unset($summary);
        }

        ksort($summaries);

        return array_values(array_map(function (array $summary): array {
            $buckets = $summary['buckets'];
            $within30 = $buckets['overdue'] + $buckets['days_0_30'];
            $within60 = $within30 + $buckets['days_31_60'];
            $within90 = $within60 + $buckets['days_61_90'];

            return [
                'currency' => $summary['currency'],
                'suppliers' => $summary['suppliers'],
                ...array_map(fn (int $cents): float => $cents / 100, $buckets),
                'total' => $summary['total'] / 100,
                'average_days_overdue' => $summary['overdue_days'] === [] ? 0.0 : round(array_sum($summary['overdue_days']) / count($summary['overdue_days']), 1),
                'projected_outflow' => [
                    'overdue' => $buckets['overdue'] / 100,
                    'within_30_days' => $within30 / 100,
                    'within_60_days' => $within60 / 100,
                    'within_90_days' => $within90 / 100,
                    'total' => $summary['total'] / 100,
                ],
            ];
        }, $summaries));
    }
}
