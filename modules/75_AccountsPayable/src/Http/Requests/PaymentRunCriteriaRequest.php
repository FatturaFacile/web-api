<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The criteria of a payment run, to preview it or to prepare it: the invoices due by the cutoff date, or whose Skonto
 * deadline falls by that date, in one currency, to be paid on the execution date.
 */
final class PaymentRunCriteriaRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'currency' => ['required', 'string', 'size:3'],
            'cutoff_date' => ['required', 'date'],
            'execution_date' => ['required', 'date', 'after_or_equal:today'],
            'bank_account_id' => ['nullable', 'uuid', Rule::exists('bank_accounts', 'id')->where('tenant_id', $tenantId)],
            'include_skonto' => ['nullable', 'boolean'],
            'net_credit_notes' => ['nullable', 'boolean'],
            'supplier_ids' => ['nullable', 'array', 'max:500'],
            'supplier_ids.*' => ['uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'item_ids' => ['nullable', 'array', 'max:2000'],
            'item_ids.*' => ['uuid'],
            'set_off_ids' => ['nullable', 'array', 'max:2000'],
            'set_off_ids.*' => ['uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array{currency: string, cutoff_date: string, execution_date: string, bank_account_id: ?string, include_skonto: bool, net_credit_notes: bool, supplier_ids: ?list<string>, item_ids: ?list<string>, set_off_ids: ?list<string>, notes: ?string}
     */
    public function criteria(): array
    {
        /** @var list<string>|null $suppliers */
        $suppliers = $this->input('supplier_ids');
        /** @var list<string>|null $itemIds */
        $itemIds = $this->has('item_ids') ? $this->input('item_ids') : null;
        /** @var list<string>|null $setOffIds */
        $setOffIds = $this->has('set_off_ids') ? $this->input('set_off_ids') : null;

        return [
            'currency' => strtoupper((string) $this->input('currency')),
            'cutoff_date' => (string) $this->input('cutoff_date'),
            'execution_date' => (string) $this->input('execution_date'),
            'bank_account_id' => $this->filled('bank_account_id') ? (string) $this->input('bank_account_id') : null,
            'include_skonto' => $this->boolean('include_skonto', true),
            'net_credit_notes' => $this->boolean('net_credit_notes', true),
            'supplier_ids' => $suppliers,
            'item_ids' => $itemIds,
            'set_off_ids' => $setOffIds,
            'notes' => $this->filled('notes') ? (string) $this->input('notes') : null,
        ];
    }
}
