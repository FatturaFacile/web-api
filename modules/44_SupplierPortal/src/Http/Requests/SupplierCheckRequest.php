<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SupplierPortal\Models\SupplierCheck;

/**
 * The outcome of a verification of a supplier.
 */
final class SupplierCheckRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware.
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
            'supplier_id' => ['required', 'uuid', $this->tenantOwns('suppliers')],
            'check_type' => ['required', Rule::in(SupplierCheck::Types)],
            'result' => ['required', Rule::in(SupplierCheck::Results)],
            'checked_at' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:checked_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
