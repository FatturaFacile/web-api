<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Services\Offboarding\OffboardingService;

final class SaveOffboardingTemplateRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $unique = Rule::unique('hr_onboarding_templates', 'name')->where('tenant_id', app(TenantContext::class)->getTenantId());

        return [
            'name' => [$required, 'string', 'max:120', $creating ? $unique : $unique->ignore((string) $this->route('id'))],
            'tasks' => [$required, 'array', 'min:1', 'max:200'],
            'tasks.*.title' => ['required', 'string', 'max:180'],
            'tasks.*.code' => ['nullable', Rule::in(OffboardingService::TaskCodes)],
            'tasks.*.due_after_days' => ['nullable', 'integer', 'between:-90,365'],
        ];
    }
}
