<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Hours on a project. Without user_id they are the hours of the caller; logging for someone else and stating rates
 * explicitly are reserved to the managers of the project (checked by the controller).
 */
#[OA\Schema(
    schema: 'ProjectsStoreTimesheetRequest',
    required: ['work_date', 'hours_worked'],
    properties: [
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'work_date', type: 'string', format: 'date'),
        new OA\Property(property: 'hours_worked', type: 'number', minimum: 0.01, maximum: 24),
        new OA\Property(property: 'overtime_hours', type: 'number', minimum: 0),
        new OA\Property(property: 'task_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'role', type: 'string', maxLength: 100, nullable: true, description: 'Role used to pick the rate card.'),
        new OA\Property(property: 'is_billable', type: 'boolean', default: true),
        new OA\Property(property: 'custom_cost_rate', type: 'number', nullable: true),
        new OA\Property(property: 'custom_charge_rate', type: 'number', nullable: true),
        new OA\Property(property: 'work_summary', type: 'string', nullable: true),
        new OA\Property(property: 'submit', type: 'boolean', default: false, description: 'Submit for approval at once instead of saving a draft.'),
    ]
)]
final class StoreTimesheetRequest extends FormRequest
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
        $creating = $this->isMethod('POST');

        return [
            'user_id' => [$creating ? 'nullable' : 'prohibited', 'uuid', $this->tenantMember()],
            'work_date' => [$creating ? 'required' : 'sometimes', 'date', 'before_or_equal:today'],
            'hours_worked' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0', 'max:24'],
            'overtime_hours' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'max:24'],
            'task_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('project_tasks')],
            'role' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_billable' => ['sometimes', 'boolean'],
            'custom_cost_rate' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'max:99999'],
            'custom_charge_rate' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'max:99999'],
            'work_summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'submit' => [$creating ? 'sometimes' : 'prohibited', 'boolean'],
        ];
    }
}
