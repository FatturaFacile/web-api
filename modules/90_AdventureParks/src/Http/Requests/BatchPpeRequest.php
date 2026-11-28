<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\AdventureParks\Domain\Enums\PpeCategory;
use Modules\AdventureParks\Domain\Enums\PpeSize;
use Modules\AdventureParks\Models\PpeAsset;

/**
 * Registers a batch of identical PPE items with serial codes PREFIX-000001, PREFIX-000002, ...
 */
final class BatchPpeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', PpeAsset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(PpeCategory::class)],
            'size' => ['nullable', Rule::enum(PpeSize::class)],
            'prefix' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-]*$/'],
            'count' => ['required', 'integer', 'between:1,500'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'batch_number' => ['nullable', 'string', 'max:60'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'first_use_date' => ['nullable', 'date_format:Y-m-d'],
            'manufacturer_max_lifespan_date' => ['required', 'date_format:Y-m-d'],
            'next_inspection_due_date' => ['nullable', 'date_format:Y-m-d'],
            'min_height_cm' => ['nullable', 'integer', 'between:30,260'],
            'max_height_cm' => ['nullable', 'integer', 'between:30,260', 'gte:min_height_cm'],
            'min_weight_kg' => ['nullable', 'integer', 'between:1,320'],
            'max_weight_kg' => ['nullable', 'integer', 'between:1,320', 'gte:min_weight_kg'],
        ];
    }
}
