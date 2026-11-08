<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * A budget revision by cost category; the total cost budget is computed as the sum of the categories.
 */
#[OA\Schema(
    schema: 'ProjectsStoreBudgetRequest',
    required: ['total_revenue_budget'],
    properties: [
        new OA\Property(property: 'total_revenue_budget', type: 'number', minimum: 0),
        new OA\Property(property: 'labour_budget', type: 'number', minimum: 0),
        new OA\Property(property: 'material_budget', type: 'number', minimum: 0),
        new OA\Property(property: 'supplier_budget', type: 'number', minimum: 0),
        new OA\Property(property: 'fleet_budget', type: 'number', minimum: 0),
        new OA\Property(property: 'expense_budget', type: 'number', minimum: 0),
        new OA\Property(property: 'contingency_budget', type: 'number', minimum: 0),
        new OA\Property(property: 'revision_reason', type: 'string', nullable: true),
        new OA\Property(property: 'lines', type: 'array', nullable: true, items: new OA\Items(properties: [
            new OA\Property(property: 'cost_category', type: 'string', enum: ['labour', 'material', 'supplier', 'fleet', 'expense', 'contingency']),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'phase_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'planned_quantity', type: 'number'),
            new OA\Property(property: 'planned_unit_cost', type: 'number'),
            new OA\Property(property: 'planned_total_cost', type: 'number', description: 'Defaults to quantity times unit cost.'),
            new OA\Property(property: 'planned_revenue_amount', type: 'number'),
        ])),
    ]
)]
final class StoreProjectBudgetRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $amount = ['nullable', 'numeric', 'gte:0', 'max:999999999999'];

        return [
            'total_revenue_budget' => ['required', 'numeric', 'gte:0', 'max:999999999999'],
            'labour_budget' => $amount,
            'material_budget' => $amount,
            'supplier_budget' => $amount,
            'fleet_budget' => $amount,
            'expense_budget' => $amount,
            'contingency_budget' => $amount,
            'revision_reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['nullable', 'array', 'max:500'],
            'lines.*.cost_category' => ['required', 'string', 'in:labour,material,supplier,fleet,expense,contingency'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.phase_id' => ['nullable', 'uuid', $this->tenantOwns('project_phases')],
            'lines.*.planned_quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.planned_unit_cost' => ['required', 'numeric', 'gte:0'],
            'lines.*.planned_total_cost' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.planned_revenue_amount' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}
