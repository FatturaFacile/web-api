<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * Filters shared by the read endpoints of the receivables (items, ledger, aging, DSO, provision, statement, credit check).
 */
final class ReceivablesQueryRequest extends FormRequest
{
    use TenantScopedRules;

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
            'customer_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', Rule::in([ReceivableItem::StatusOpen, ReceivableItem::StatusPartiallyPaid, ReceivableItem::StatusPaid, ReceivableItem::StatusOverdue, ReceivableItem::StatusDisputed, ReceivableItem::StatusWrittenOff])],
            'document_type' => ['nullable', Rule::in(['invoice', 'debit_note'])],
            'bucket' => ['nullable', 'string', 'max:20'],
            'open_only' => ['nullable', 'boolean'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
            'issued_from' => ['nullable', 'date'],
            'issued_to' => ['nullable', 'date', 'after_or_equal:issued_from'],
            'value_from' => ['nullable', 'date'],
            'value_to' => ['nullable', 'date', 'after_or_equal:value_from'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'period_days' => ['nullable', 'integer', 'min:1', 'max:3660'],
            'scope' => ['nullable', Rule::in(['order', 'delivery_note'])],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
