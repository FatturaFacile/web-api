<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates (POST) or changes (PUT) an entry of the skill taxonomy.
 */
final class SkillRequest extends FormRequest
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
            'category' => ['required', 'string', 'max:60'],
            'skill_name' => [
                'required', 'string', 'max:150',
                Rule::unique('rp_skills', 'skill_name')
                    ->where('tenant_id', $tenantId)
                    ->where('category', $this->input('category'))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('id')),
            ],
            'requires_certification' => ['sometimes', 'boolean'],
            'certification_code' => ['nullable', 'string', 'max:60', 'required_if:requires_certification,true'],
        ];
    }
}
