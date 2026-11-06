<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreJournalEntryRequest extends FormRequest
{
    /**
     * Written only by the posting of the document itself: a manual entry must not take its place in the ledger.
     *
     * @var list<string>
     */
    private const array SYSTEM_DOCUMENT_TYPES = ['sales_invoice', 'credit_note', 'vendor_invoice', 'vendor_credit_note', 'customer_payment', 'supplier_payment', 'treasury_payment_line', 'treasury_collection', 'payroll_run'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fiscal_period_id' => ['required', 'uuid', 'exists:fiscal_periods,id'],
            'batch_id' => ['nullable', 'uuid', 'exists:journal_batches,id'],
            'entry_number' => ['nullable', 'string', 'max:50'],
            'posting_date' => ['required', 'date'],
            'document_date' => ['nullable', 'date'],
            'ledger_type' => ['nullable', 'string', 'in:statutory,ifrs,management'],
            'journal_type' => ['nullable', 'string', 'in:standard,reversing,opening,recurring,adjusting'],
            'description' => ['required', 'string', 'max:500'],
            'source_document_type' => ['nullable', 'string', 'max:50', Rule::notIn(self::SYSTEM_DOCUMENT_TYPES)],
            'source_document_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'in:draft,posted'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'uuid', 'exists:chart_of_accounts,id'],
            'lines.*.debit_amount' => ['required_without:lines.*.credit_amount', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['required_without:lines.*.debit_amount', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.cost_center_id' => ['nullable', 'uuid', 'exists:cost_centers,id'],
            'lines.*.profit_center_id' => ['nullable', 'uuid', 'exists:profit_centers,id'],
            'lines.*.partner_type' => ['nullable', 'string', 'in:customer,supplier,employee'],
            'lines.*.partner_id' => ['nullable', 'uuid'],
        ];
    }
}
