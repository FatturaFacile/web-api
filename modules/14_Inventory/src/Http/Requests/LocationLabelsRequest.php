<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * Bins to print labels for, the output format and the label size.
 */
final class LocationLabelsRequest extends FormRequest
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
            'bin_ids' => ['required', 'array', 'min:1', 'max:500'],
            'bin_ids.*' => ['uuid', $this->tenantExists('warehouse_bins')],
            'format' => ['required', Rule::in(['pdf', 'zpl'])],
            'size' => ['sometimes', Rule::in(['100x50', '50x25'])],
        ];
    }
}
