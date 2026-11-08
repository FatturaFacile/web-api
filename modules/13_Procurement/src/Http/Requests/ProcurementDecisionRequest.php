<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Small decisions on procurement documents: a reason, the ids of suggestions to order, or the credit note of a return.
 */
final class ProcurementDecisionRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:2000'],
            'suggestion_ids' => ['sometimes', 'array', 'min:1'],
            'suggestion_ids.*' => ['uuid'],
            'passive_invoice_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('passive_invoices')],
            'credit_note_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('cost_centers')],
        ];
    }
}
