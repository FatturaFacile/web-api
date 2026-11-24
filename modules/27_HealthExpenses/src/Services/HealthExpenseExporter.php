<?php

declare(strict_types=1);

namespace Modules\HealthExpenses\Services;

use App\Models\Tenant;
use Modules\HealthExpenses\Models\HealthExpense;

/**
 * Rows of the Sistema Tessera Sanitaria data, with the field names of the published technical document (web service
 * v1.3 of 20/12/2020). This is the data layer, not the official file: the XSD of the development kit was not available
 * and the fiscal codes are not encrypted with the certificate of the Sistema TS, so it must not be sent as it is.
 */
final class HealthExpenseExporter
{
    public const string FormatStatus = 'unverified_data_layer';

    /**
     * @var list<string>
     */
    public const array Columns = [
        'pIva', 'dataEmissione', 'dispositivo', 'numDocumento', 'dataPagamento', 'flagPagamentoAnticipato', 'flagOperazione',
        'cfCittadino', 'tipoSpesa', 'flagTipoSpesa', 'importo', 'aliquotaIVA', 'naturaIVA', 'pagamentoTracciato', 'tipoDocumento',
        'flagOpposizione',
    ];

    /**
     * Totals of the expenses paid in a year, by expense type: what the practice is about to report, before the
     * export. Amounts are decimal strings added with bcmath. Expenses whose patient opposed the transmission are
     * counted apart: they are reported without the fiscal code of the citizen.
     *
     * @return array{year: int, count: int, total_amount: string, opposed_count: int, by_expense_type: list<array{expense_type: string, count: int, total_amount: string}>}
     */
    public function summary(int $year): array
    {
        $expenses = HealthExpense::query()->whereYear('payment_date', $year)->orderBy('expense_type')->get();
        $byType = [];
        $total = '0.00';

        foreach ($expenses as $expense) {
            $type = (string) $expense->expense_type;
            $byType[$type] ??= ['expense_type' => $type, 'count' => 0, 'total_amount' => '0.00'];
            $byType[$type]['count']++;
            $byType[$type]['total_amount'] = bcadd($byType[$type]['total_amount'], (string) $expense->amount, 2);
            $total = bcadd($total, (string) $expense->amount, 2);
        }

        return [
            'year' => $year,
            'count' => $expenses->count(),
            'total_amount' => $total,
            'opposed_count' => $expenses->where('patient_opposition', true)->count(),
            'by_expense_type' => array_values($byType),
        ];
    }

    /**
     * Rows of the expenses paid in the given year of the tenant.
     *
     * @return list<array<string, string>>
     */
    public function rows(Tenant $tenant, int $year): array
    {
        return HealthExpense::query()
            ->whereYear('payment_date', $year)
            ->orderBy('payment_date')
            ->orderBy('document_number')
            ->get()
            ->map(fn (HealthExpense $expense): array => [
                'pIva' => (string) preg_replace('/^IT/i', '', (string) $tenant->vat_number),
                'dataEmissione' => $expense->issue_date->toDateString(),
                'dispositivo' => (string) $expense->device_number,
                'numDocumento' => (string) $expense->document_number,
                'dataPagamento' => $expense->payment_date->toDateString(),
                'flagPagamentoAnticipato' => $expense->is_advance_payment ? '1' : '',
                'flagOperazione' => (string) $expense->operation,
                'cfCittadino' => $expense->patient_opposition ? '' : (string) $expense->patient_fiscal_code,
                'tipoSpesa' => (string) $expense->expense_type,
                'flagTipoSpesa' => (string) $expense->expense_flag,
                'importo' => (string) $expense->amount,
                'aliquotaIVA' => (string) $expense->tax_rate,
                'naturaIVA' => (string) $expense->vat_nature,
                'pagamentoTracciato' => $expense->payment_traceable ? 'SI' : 'NO',
                'tipoDocumento' => (string) $expense->document_kind,
                'flagOpposizione' => $expense->patient_opposition ? '1' : '0',
            ])
            ->all();
    }
}
