<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the completion of a signed field intervention together with its settlement (contract hours or invoice).
 */
final class CompleteAndBillWorkOrderRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access:service middleware.
     */
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
            'completion_notes' => ['nullable', 'string', 'max:5000'],
            'resolution_summary' => ['nullable', 'string', 'max:5000'],
            'customer_signature_url' => ['nullable', 'string', 'max:1000000'],
            'signed_by_customer_name' => ['nullable', 'string', 'max:150'],
            'signed_at' => ['nullable', 'date'],
            'tax_rate' => ['required', 'numeric', 'between:0,100'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'revenue_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
        ];
    }
}
