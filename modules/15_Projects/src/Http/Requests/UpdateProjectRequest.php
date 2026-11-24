<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\Project;
use OpenApi\Attributes as OA;

/**
 * Changing the master data of a project. The status moves only through the lifecycle endpoints (start, pause, resume,
 * close), and the contract value only while the project is draft or planned: afterwards it moves through change orders.
 */
#[OA\Schema(
    schema: 'ProjectsUpdateProjectRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'contract_value', type: 'number', minimum: 0, description: 'Only while draft or planned.'),
        new OA\Property(property: 'progress_percentage', type: 'number', minimum: 0, maximum: 100),
        new OA\Property(property: 'project_manager_user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'revenue_recognition_method', type: 'string', enum: Project::RecognitionMethods, nullable: true, description: 'Only while no cost is booked.'),
        new OA\Property(property: 'billing_method', type: 'string'),
        new OA\Property(property: 'cup_code', type: 'string', maxLength: 15, nullable: true, description: 'CUP (Codice Unico di Progetto), passed on to purchase orders and e-invoices.'),
        new OA\Property(property: 'cig_code', type: 'string', maxLength: 10, nullable: true, description: 'CIG (Codice Identificativo Gara), passed on to purchase orders and e-invoices.'),
        new OA\Property(property: 'sdi_reference_block', type: 'string', enum: ['contract', 'convention'], description: 'FatturaPA block that carries CUP and CIG: DatiContratto or DatiConvenzione.'),
    ]
)]
final class UpdateProjectRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'planned_start_date' => ['sometimes', 'date'],
            'planned_end_date' => ['sometimes', 'date', 'after_or_equal:planned_start_date'],
            'contract_value' => ['sometimes', 'numeric', 'gte:0', 'max:999999999999'],
            'progress_percentage' => ['sometimes', 'numeric', 'between:0,100'],
            'project_manager_user_id' => ['sometimes', 'uuid', $this->tenantMember()],
            'revenue_recognition_method' => ['sometimes', 'nullable', 'string', Rule::in(Project::RecognitionMethods)],
            'billing_method' => ['sometimes', 'string', Rule::in(StoreProjectRequest::BillingMethods)],
            'cup_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{15}$/'],
            'cig_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{10}$/'],
            'sdi_reference_block' => ['sometimes', 'nullable', 'string', Rule::in(['contract', 'convention'])],
        ];
    }
}
