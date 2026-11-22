<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * A manual ABC class of an item (null = back to the classification), always with a reason for the audit trail.
 */
final class AbcOverrideRequest extends FormRequest
{
    use ValidatesTenantRecords;

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
            'stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'abc_class' => ['present', 'nullable', 'string', Rule::in(['A', 'B', 'C'])],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
