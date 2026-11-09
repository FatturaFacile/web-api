<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the invoicing of a completed work order. The VAT rate is stated by the caller: a work order carries none.
 */
final class InvoiceWorkOrderRequest extends FormRequest
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
            'tax_rate' => ['required', 'numeric', 'between:0,100'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'revenue_account_id' => ['nullable', 'uuid', $this->tenantOwns('chart_of_accounts')],
        ];
    }
}
