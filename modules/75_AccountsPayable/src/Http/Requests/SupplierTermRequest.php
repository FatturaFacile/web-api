<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A cash-discount agreement with a supplier: a percentage and days, or the text of the payment clause.
 */
final class SupplierTermRequest extends FormRequest
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
        $creating = $this->isMethod('POST');

        return [
            'supplier_id' => $creating ? ['required', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)] : ['prohibited'],
            'clause' => ['nullable', 'string', 'max:255', Rule::requiredIf($creating && ! $this->filled('skonto_percentage'))],
            'skonto_percentage' => ['nullable', 'numeric', 'gt:0', 'max:100', Rule::requiredIf($creating && $this->filled('skonto_days'))],
            'skonto_days' => ['nullable', 'integer', 'min:0', 'max:365', Rule::requiredIf($creating && $this->filled('skonto_percentage'))],
            'net_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'reference' => ['nullable', 'string', 'max:100'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
