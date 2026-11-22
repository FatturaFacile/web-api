<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests\Receivables;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Models\ReceivableAllocation;

/**
 * One or more allocations (reconciliation of open items by payments, credit notes, write-offs, discounts, debit adjustments).
 */
final class AllocateReceivablesRequest extends FormRequest
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
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.receivable_item_id' => ['required', 'uuid', $this->tenantOwns('receivable_items')],
            'allocations.*.type' => ['required', Rule::in(ReceivableAllocation::Types)],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999', 'decimal:0,2'],
            'allocations.*.value_date' => ['required', 'date'],
            'allocations.*.source_id' => ['nullable', 'uuid'],
            'allocations.*.reference' => ['nullable', 'string', 'max:100'],
            'allocations.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
