<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * An hourly cost and billing rate for a person or a role, optionally limited to a project and a validity period.
 */
#[OA\Schema(
    schema: 'ProjectsStoreRateCardRequest',
    required: ['cost_rate', 'bill_rate'],
    properties: [
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true, description: 'Either user_id or role is required.'),
        new OA\Property(property: 'role', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'cost_rate', type: 'number', minimum: 0),
        new OA\Property(property: 'bill_rate', type: 'number', minimum: 0),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    ]
)]
final class StoreRateCardRequest extends FormRequest
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
            'project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'user_id' => ['nullable', 'required_without:role', 'uuid', $this->tenantMember()],
            'role' => ['nullable', 'required_without:user_id', 'string', 'max:100'],
            'cost_rate' => ['required', 'numeric', 'gte:0', 'max:99999'],
            'bill_rate' => ['required', 'numeric', 'gte:0', 'max:99999'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
