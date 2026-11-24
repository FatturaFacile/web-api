<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the items of `/api/v1/accounting/payables/items`, in the names of the web client.
 */
final class PayablesItemsIndexRequest extends FormRequest
{
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
            'supplier_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['open', 'in_batch', 'authorized', 'paid', 'disputed', 'blocked'])],
            'document_type' => ['nullable', Rule::in(['xml_invoice', 'qr_bill', 'manual_bill', 'credit_note'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
            'skonto_only' => ['nullable', 'boolean'],
            'open_only' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The filters of OpenItemService::paginate.
     *
     * @return array{supplier_id: ?string, status: ?string, kind: ?string, source: ?string, currency: ?string, due_from: ?string, due_to: ?string, search: ?string, with_balance: ?bool, skonto_eligible: ?bool}
     */
    public function filters(): array
    {
        $type = $this->filled('document_type') ? (string) $this->input('document_type') : null;
        $status = $this->filled('status') ? (string) $this->input('status') : null;

        return [
            'supplier_id' => $this->filled('supplier_id') ? (string) $this->input('supplier_id') : null,
            'status' => $status === 'in_batch' ? 'in_proposal' : $status,
            'kind' => $type === null ? null : ($type === 'credit_note' ? 'credit_note' : 'invoice'),
            'source' => match ($type) {
                'xml_invoice' => 'electronic_xml',
                'qr_bill' => 'swiss_qr',
                'manual_bill' => 'manual',
                default => null,
            },
            'currency' => $this->filled('currency') ? strtoupper((string) $this->input('currency')) : null,
            'due_from' => $this->filled('due_from') ? (string) $this->input('due_from') : null,
            'due_to' => $this->filled('due_to') ? (string) $this->input('due_to') : null,
            'search' => $this->filled('search') ? (string) $this->input('search') : null,
            'with_balance' => $this->has('open_only') ? $this->boolean('open_only') : null,
            'skonto_eligible' => $this->has('skonto_only') ? $this->boolean('skonto_only') : null,
        ];
    }
}
