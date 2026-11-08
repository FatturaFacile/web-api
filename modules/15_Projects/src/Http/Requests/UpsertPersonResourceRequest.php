<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Default rates and weekly capacity of a person working on projects.
 */
#[OA\Schema(
    schema: 'ProjectsUpsertPersonResourceRequest',
    required: ['internal_hourly_cost', 'billing_hourly_rate'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 150, nullable: true),
        new OA\Property(property: 'internal_hourly_cost', type: 'number', minimum: 0),
        new OA\Property(property: 'billing_hourly_rate', type: 'number', minimum: 0),
        new OA\Property(property: 'weekly_capacity_hours', type: 'number', minimum: 0, maximum: 168, nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
final class UpsertPersonResourceRequest extends FormRequest
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
        return [
            'name' => ['nullable', 'string', 'max:150'],
            'internal_hourly_cost' => ['required', 'numeric', 'gte:0', 'max:99999'],
            'billing_hourly_rate' => ['required', 'numeric', 'gte:0', 'max:99999'],
            'weekly_capacity_hours' => ['nullable', 'numeric', 'gte:0', 'max:168'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
