<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * A change order with its cost, revenue and schedule impact; with lines, the cost impact must equal their sum.
 */
#[OA\Schema(
    schema: 'ProjectsStoreChangeOrderRequest',
    required: ['order_number', 'title', 'scope_description', 'cost_impact_amount'],
    properties: [
        new OA\Property(property: 'order_number', type: 'string', maxLength: 100),
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'scope_description', type: 'string'),
        new OA\Property(property: 'cost_impact_amount', type: 'number', description: 'Negative for a reduction of scope.'),
        new OA\Property(property: 'revenue_impact_amount', type: 'number'),
        new OA\Property(property: 'schedule_impact_days', type: 'integer'),
        new OA\Property(property: 'lines', type: 'array', nullable: true, items: new OA\Items(properties: [
            new OA\Property(property: 'cost_category', type: 'string', enum: ['labour', 'material', 'supplier', 'fleet', 'expense', 'equipment', 'contingency']),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'quantity_delta', type: 'number'),
            new OA\Property(property: 'unit_cost_delta', type: 'number'),
            new OA\Property(property: 'total_cost_delta', type: 'number'),
            new OA\Property(property: 'revenue_delta', type: 'number'),
        ])),
    ]
)]
final class StoreChangeOrderRequest extends FormRequest
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
            'order_number' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'scope_description' => ['required', 'string', 'max:5000'],
            'cost_impact_amount' => ['required', 'numeric', 'between:-999999999999,999999999999'],
            'revenue_impact_amount' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'schedule_impact_days' => ['nullable', 'integer', 'between:-3650,3650'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.cost_category' => ['required', 'string', 'in:labour,material,supplier,fleet,expense,equipment,contingency'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity_delta' => ['required', 'numeric'],
            'lines.*.unit_cost_delta' => ['required', 'numeric'],
            'lines.*.total_cost_delta' => ['required', 'numeric'],
            'lines.*.revenue_delta' => ['nullable', 'numeric'],
        ];
    }
}
