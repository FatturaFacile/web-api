<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * A milestone; with a billing amount (or a percentage of the contract value) it becomes billable once completed.
 */
#[OA\Schema(
    schema: 'ProjectsStoreMilestoneRequest',
    required: ['name', 'milestone_date'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'milestone_date', type: 'string', format: 'date'),
        new OA\Property(property: 'project_phase_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'billing_amount', type: 'number', minimum: 0),
        new OA\Property(property: 'billing_percentage', type: 'number', minimum: 0, maximum: 100),
    ]
)]
final class StoreMilestoneRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'milestone_date' => [$required, 'date'],
            'project_phase_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('project_phases')],
            'billing_amount' => ['sometimes', 'numeric', 'gte:0', 'max:999999999999'],
            'billing_percentage' => ['sometimes', 'numeric', 'between:0,100'],
        ];
    }
}
