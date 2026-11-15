<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sustainability\Support\ActivityCatalog;

/**
 * A request of supplier-specific emission data for a Scope 3 category.
 */
final class SupplierDataRequestRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware and the policy.
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
            'reporting_period_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'uuid'],
            'scope3_category' => ['required', 'integer', Rule::in(array_keys(ActivityCatalog::Scope3Categories))],
            'activity_type' => ['required', Rule::in(ActivityCatalog::types())],
            'unit' => ['required', Rule::in(ActivityCatalog::units())],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999999999'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
