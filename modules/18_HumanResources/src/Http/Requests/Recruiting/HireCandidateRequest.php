<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class HireCandidateRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'work_email' => ['nullable', 'email', 'max:180'],
            'manager_id' => ['nullable', 'uuid', Rule::exists('hr_employees', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'start_onboarding' => ['nullable', 'boolean'],
        ];
    }
}
