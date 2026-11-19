<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Tax;

use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Quarterly report of the virtual stamp duty (bollo virtuale) charged on issued e-invoices, with the F24 payment
 * data. Invoices are assigned to a quarter by invoice date; credit notes and pro-forma documents are excluded.
 */
class StampDutyReportService
{
    /**
     * @return array{year: int, quarter: int, count: int, total_amount: string, f24: array{tributo: string, due_date: string, deferrable: bool}, invoices: list<array{id: string, number: string, date: string, customer: string, amount: string}>}
     */
    public function quarterly(int $year, int $quarter): array
    {
        $from = Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();
        $to = $from->copy()->addMonths(2)->endOfMonth();

        $invoices = Invoice::query()
            ->with('contact')
            ->where('stamp_duty_applied', true)
            ->whereNotIn('invoice_type', ['credit_note', 'proforma'])
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->orderBy('invoice_number')
            ->get();

        $rows = [];
        $total = 0;

        foreach ($invoices as $invoice) {
            $amount = Money::toCents((string) ($invoice->stamp_duty_amount ?? config('italian_tax.stamp_duty.amount', '2.00')));
            $total += $amount;
            $contact = $invoice->contact;

            $rows[] = [
                'id' => (string) $invoice->id,
                'number' => (string) $invoice->invoice_number,
                'date' => (string) $invoice->date->toDateString(),
                'customer' => $contact === null ? '' : (string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name)),
                'amount' => Money::fromCents($amount),
            ];
        }

        /** @var array{tributo: string, due_month: int, due_day: int, next_year: bool} $f24 */
        $f24 = config("italian_tax.stamp_duty.f24.{$quarter}");
        $dueYear = $f24['next_year'] ? $year + 1 : $year;
        $dueMonth = Carbon::create($dueYear, $f24['due_month'], 1);
        $dueDate = $dueMonth->copy()->day(min($f24['due_day'], $dueMonth->daysInMonth));

        return [
            'year' => $year,
            'quarter' => $quarter,
            'count' => count($rows),
            'total_amount' => Money::fromCents($total),
            'f24' => [
                'tributo' => $f24['tributo'],
                'due_date' => $dueDate->toDateString(),
                'deferrable' => in_array($quarter, [1, 2], true)
                    && $total < Money::toCents((string) config('italian_tax.stamp_duty.deferral_threshold', '5000.00')),
            ],
            'invoices' => $rows,
        ];
    }

    /**
     * The report as CSV (one row per invoice, then a total row).
     *
     * @param  array{year: int, quarter: int, count: int, total_amount: string, f24: array{tributo: string, due_date: string, deferrable: bool}, invoices: list<array{id: string, number: string, date: string, customer: string, amount: string}>}  $report
     */
    public function toCsv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['invoice_number', 'date', 'customer', 'amount'], escape: '');

        foreach ($report['invoices'] as $row) {
            fputcsv($handle, [$row['number'], $row['date'], $row['customer'], $row['amount']], escape: '');
        }

        fputcsv($handle, ['TOTAL', '', "F24 {$report['f24']['tributo']} due {$report['f24']['due_date']}", $report['total_amount']], escape: '');

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
