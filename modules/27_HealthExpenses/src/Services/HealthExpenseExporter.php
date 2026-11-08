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
