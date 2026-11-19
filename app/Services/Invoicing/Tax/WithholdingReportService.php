<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceWithholding;
use App\Support\Money;

/**
 * CU (Certificazione Unica) withholding report: withholdings of received supplier invoices, grouped by supplier and
 * causale, by payment year (cash principle). The payment date is the latest supplier payment of a fully paid invoice;
 * invoices not paid yet are listed under `unpaid_withholdings` and stay out of the totals.
 */
class WithholdingReportService
{
    /**
     * Withholding types certified in the CU (ENASARCO and other contributions are excluded).
     */
    private const CU_TYPES = ['RT01', 'RT02'];

    /**
     * @return array{year: int, suppliers: list<array{supplier_id: string, name: string, fiscal_code: string|null, vat_number: string|null, reason: string|null, invoices: int, gross_paid: string, taxable: string, withheld: string}>, totals: array{gross_paid: string, taxable: string, withheld: string}, unpaid_withholdings: list<array{passive_invoice_id: string, invoice_number: string, date: string, supplier_id: string, name: string, reason: string|null, taxable: string, withheld: string}>}
     */
    public function annual(int $year): array
    {
        $withholdings = PassiveInvoiceWithholding::query()
            ->with(['passiveInvoice.payments', 'supplier'])
            ->whereIn('type', self::CU_TYPES)
            ->where(fn ($query) => $query->whereNull('withheld_year')->orWhere('withheld_year', '<=', $year))
            ->get();

        $groups = [];
        $unpaid = [];

        foreach ($withholdings as $withholding) {
            $invoice = $withholding->passiveInvoice;

            if ($invoice === null) {
                continue;
            }

            $paidYear = $this->paymentYear($invoice);
            $taxable = Money::toCents((string) ($withholding->taxable_amount ?? '0'));
            $withheld = Money::toCents((string) $withholding->amount);
            $sign = $invoice->document_type === 'TD04' ? -1 : 1;

            if ($paidYear === null) {
                if ((int) ($withholding->withheld_year ?? $invoice->date->year) === $year) {
                    $unpaid[] = [
                        'passive_invoice_id' => (string) $invoice->id,
                        'invoice_number' => (string) $invoice->invoice_number,
                        'date' => (string) $invoice->date->toDateString(),
                        'supplier_id' => (string) $withholding->supplier_id,
                        'name' => $this->name($withholding->supplier),
                        'reason' => $withholding->reason,
                        'taxable' => Money::fromCents($sign * $taxable),
                        'withheld' => Money::fromCents($sign * $withheld),
                    ];
                }

                continue;
            }

            if ($paidYear !== $year) {
                continue;
            }

            $key = $withholding->supplier_id.'|'.($withholding->reason ?? '');
            $groups[$key] ??= [
                'supplier' => $withholding->supplier,
                'supplier_id' => (string) $withholding->supplier_id,
                'reason' => $withholding->reason,
                'invoice_ids' => [],
                'gross_paid' => 0,
                'taxable' => 0,
                'withheld' => 0,
            ];

            // The compensation of an invoice is counted once per group, even with several withholdings.
            if (! in_array($invoice->id, $groups[$key]['invoice_ids'], true)) {
                $groups[$key]['invoice_ids'][] = $invoice->id;
                $groups[$key]['gross_paid'] += $sign * Money::toCents((string) $invoice->total_net);
            }

            $groups[$key]['taxable'] += $sign * $taxable;
            $groups[$key]['withheld'] += $sign * $withheld;
        }

        $suppliers = [];
        $totals = ['gross_paid' => 0, 'taxable' => 0, 'withheld' => 0];

        foreach ($groups as $group) {
            $totals['gross_paid'] += $group['gross_paid'];
            $totals['taxable'] += $group['taxable'];
            $totals['withheld'] += $group['withheld'];

            $suppliers[] = [
                'supplier_id' => $group['supplier_id'],
                'name' => $this->name($group['supplier']),
                'fiscal_code' => $group['supplier']?->fiscal_code,
                'vat_number' => $group['supplier']?->vat_number,
                'reason' => $group['reason'],
                'invoices' => count($group['invoice_ids']),
                'gross_paid' => Money::fromCents($group['gross_paid']),
                'taxable' => Money::fromCents($group['taxable']),
                'withheld' => Money::fromCents($group['withheld']),
            ];
        }

        usort($suppliers, fn (array $a, array $b): int => [$a['name'], (string) $a['reason']] <=> [$b['name'], (string) $b['reason']]);

        return [
            'year' => $year,
            'suppliers' => $suppliers,
            'totals' => [
                'gross_paid' => Money::fromCents($totals['gross_paid']),
                'taxable' => Money::fromCents($totals['taxable']),
                'withheld' => Money::fromCents($totals['withheld']),
            ],
            'unpaid_withholdings' => $unpaid,
        ];
    }

    /**
     * The supplier rows as CSV.
     *
     * @param  array{year: int, suppliers: list<array{supplier_id: string, name: string, fiscal_code: string|null, vat_number: string|null, reason: string|null, invoices: int, gross_paid: string, taxable: string, withheld: string}>, totals: array{gross_paid: string, taxable: string, withheld: string}, unpaid_withholdings: list<array<string, mixed>>}  $report
     */
    public function toCsv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['supplier', 'fiscal_code', 'vat_number', 'reason', 'invoices', 'gross_paid', 'taxable', 'withheld'], escape: '');

        foreach ($report['suppliers'] as $row) {
            fputcsv($handle, [
                $row['name'], $row['fiscal_code'] ?? '', $row['vat_number'] ?? '', $row['reason'] ?? '', $row['invoices'],
                $row['gross_paid'], $row['taxable'], $row['withheld'],
            ], escape: '');
        }

        fputcsv($handle, ['TOTAL', '', '', '', '', $report['totals']['gross_paid'], $report['totals']['taxable'], $report['totals']['withheld']], escape: '');

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Year of the last payment of a fully paid invoice, or null when it is not paid yet.
     */
    private function paymentYear(PassiveInvoice $invoice): ?int
    {
        if ($invoice->payment_status !== 'paid') {
            return null;
        }

        $lastPayment = $invoice->payments
            ->where('type', 'payment')
            ->sortByDesc(fn ($payment): string => (string) $payment->paid_at->toDateString())
            ->first();

        return $lastPayment?->paid_at?->year;
    }

    private function name(?Contact $contact): string
    {
        if ($contact === null) {
            return '';
        }

        return (string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name));
    }
}
