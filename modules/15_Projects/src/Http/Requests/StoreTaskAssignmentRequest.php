<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * A member of the company assigned to the task, with the hours planned for them on it.
 */
#[OA\Schema(
    schema: 'ProjectsStoreTaskAssignmentRequest',
    required: ['user_id', 'allocated_hours'],
    properties: [
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'allocated_hours', type: 'number', minimum: 0),
        new OA\Property(property: 'role_description', type: 'string', maxLength: 100, nullable: true),
    ]
)]
final class StoreTaskAssignmentRequest extends FormRequest
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
            'user_id' => ['required', 'uuid', $this->tenantMember()],
            'allocated_hours' => ['required', 'numeric', 'gte:0', 'max:99999'],
            'role_description' => ['nullable', 'string', 'max:100'],
        ];
    }
}
