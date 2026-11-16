<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A line of the qualification checklist, on creation (POST) or change (PUT).
 */
final class QualificationCriterionRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'code' => [$presence, 'string', 'max:40', 'regex:/^[A-Z0-9_]+$/', Rule::unique('sp_qualification_criteria', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())->ignore($this->route('id'))->whereNull('deleted_at')],
            'name' => [$presence, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => [$presence, 'numeric', 'gt:0', 'max:1000'],
            'max_score' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ];
    }
}
