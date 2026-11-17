<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Tenancy\AccessPlanLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The plan of a tenant and its optional user / custom role limits (null removes an override).
 */
class UpdateTenantPlanRequest extends FormRequest
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
            'plan_code' => ['sometimes', 'string', Rule::in(app(AccessPlanLimits::class)->planCodes())],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'max_custom_roles' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
