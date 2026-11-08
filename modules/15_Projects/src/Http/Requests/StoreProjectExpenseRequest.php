<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * An out-of-pocket project expense, recorded as pending until approved.
 */
#[OA\Schema(
    schema: 'ProjectsStoreExpenseRequest',
    required: ['expense_category', 'description', 'amount', 'incurred_date'],
    properties: [
        new OA\Property(property: 'expense_category', type: 'string', enum: StoreProjectExpenseRequest::Types),
        new OA\Property(property: 'description', type: 'string', maxLength: 255),
        new OA\Property(property: 'amount', type: 'number', minimum: 0.01),
        new OA\Property(property: 'incurred_date', type: 'string', format: 'date'),
        new OA\Property(property: 'phase_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'task_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'receipt_document_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'is_billable', type: 'boolean'),
    ]
)]
final class StoreProjectExpenseRequest extends FormRequest
{
    use TenantScopedRules;

    public const array Types = ['travel_lodging', 'meals_subsistence', 'licensing_permits', 'site_rentals', 'laboratory_testing', 'expense', 'subcontractor', 'equipment'];

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
            'expense_category' => ['required', 'string', 'in:'.implode(',', self::Types)],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'incurred_date' => ['required', 'date', 'before_or_equal:today'],
            'phase_id' => ['nullable', 'uuid', $this->tenantOwns('project_phases')],
            'task_id' => ['nullable', 'uuid', $this->tenantOwns('project_tasks')],
            'receipt_document_id' => ['nullable', 'uuid'],
            'is_billable' => ['nullable', 'boolean'],
        ];
    }
}
