<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The criteria of a payment batch of `/api/v1/accounting/payables/batches`: the items due by the cutoff date (or whose Skonto
 * deadline falls by it), narrowed down to the items and credit notes ticked in the wizard. Creating the batch needs the ticked
 * items; the preview does not.
 */
final class PayablesBatchCriteriaRequest extends FormRequest
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
        $creating = ! $this->routeIs('accounts-payable.payables.batches.preview');

        return [
            'cutoff_date' => ['required', 'date'],
            'prioritize_skonto' => ['required', 'boolean'],
            'currency' => ['nullable', 'string', 'size:3'],
            'supplier_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'item_ids' => [$creating ? 'required' : 'nullable', 'array', 'max:2000', $creating ? 'min:1' : 'min:0'],
            'item_ids.*' => ['uuid'],
            'set_off_ids' => [$creating ? 'present' : 'nullable', 'array', 'max:2000'],
            'set_off_ids.*' => ['uuid'],
        ];
    }

    /**
     * The criteria of PaymentRunService, paid today.
     *
     * @return array{currency: string, cutoff_date: string, execution_date: string, include_skonto: bool, net_credit_notes: bool, supplier_ids: ?list<string>, item_ids: ?list<string>, set_off_ids: ?list<string>}
     */
    public function criteria(): array
    {
        $tenantId = (string) app(TenantContext::class)->getTenantId();
        /** @var list<string>|null $itemIds */
        $itemIds = $this->has('item_ids') ? $this->input('item_ids') : null;
        /** @var list<string>|null $setOffIds */
        $setOffIds = $this->has('set_off_ids') ? $this->input('set_off_ids') : null;

        return [
            'currency' => strtoupper((string) ($this->input('currency') ?: Tenant::query()->whereKey($tenantId)->value('default_currency') ?: 'EUR')),
            'cutoff_date' => (string) $this->input('cutoff_date'),
            'execution_date' => now()->toDateString(),
            'include_skonto' => $this->boolean('prioritize_skonto'),
            'net_credit_notes' => true,
            'supplier_ids' => $this->filled('supplier_id') ? [(string) $this->input('supplier_id')] : null,
            'item_ids' => $itemIds,
            'set_off_ids' => $setOffIds,
        ];
    }
}
