<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creation or update of a pipeline stage. The code is unique per tenant and immutable after creation, because
 * integrations and the stage-change endpoint address stages by code.
 */
final class SavePipelineStageRequest extends FormRequest
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
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => $creating
                ? ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('crm_opportunity_stages', 'code')->where('tenant_id', app(TenantContext::class)->getTenantId())]
                : ['prohibited'],
            'name' => [$required, 'string', 'max:100'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'default_probability' => [$required, 'numeric', 'between:0,100'],
            'is_won' => ['nullable', 'boolean'],
            'is_lost' => ['nullable', 'boolean'],
            'stale_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'reprice_open_opportunities' => ['nullable', 'boolean'],
        ];
    }
}
