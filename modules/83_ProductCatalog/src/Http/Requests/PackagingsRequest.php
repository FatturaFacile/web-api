<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ProductCatalog\Domain\Enums\PackagingRole;
use Modules\ProductCatalog\Domain\Enums\RoundingMode;
use Modules\ProductCatalog\Http\Requests\Concerns\ScopesToTenant;

/**
 * The packaging hierarchy of a product and its rounding policy.
 */
final class PackagingsRequest extends FormRequest
{
    use ScopesToTenant;

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
            'uom_rounding' => ['sometimes', Rule::enum(RoundingMode::class)],
            'uom_precision' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'levels' => ['present', 'array', 'max:12'],
            'levels.*.code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\-]+$/', 'distinct:ignore_case'],
            'levels.*.uom_id' => ['required', 'uuid', $this->sharedExists('units_of_measure')],
            'levels.*.units_per_parent' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'levels.*.parent_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'levels.*.role' => ['sometimes', Rule::enum(PackagingRole::class)],
            'levels.*.gtin' => ['sometimes', 'nullable', 'string', 'regex:/^\d{8}$|^\d{12,14}$/'],
        ];
    }
}
