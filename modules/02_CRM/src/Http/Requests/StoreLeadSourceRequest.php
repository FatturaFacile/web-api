<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\LeadSource;

/**
 * Create (POST) and update (PUT) of a lead source. Credentials are generated server side, never accepted.
 */
final class StoreLeadSourceRequest extends FormRequest
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
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $required = $isUpdate ? 'sometimes' : 'required';
        $tenantId = app(TenantContext::class)->getTenantId();
        $sourceId = $this->route('id');

        return [
            'code' => [
                $required, 'string', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/',
                Rule::unique('crm_lead_sources', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore(is_string($sourceId) ? $sourceId : null),
            ],
            'name' => [$required, 'string', 'max:100'],
            'type' => [$isUpdate ? 'sometimes' : 'nullable', 'string', Rule::in(LeadSource::TYPES)],
            'is_active' => ['sometimes', 'boolean'],
            'allowed_origins' => ['nullable', 'array', 'max:20'],
            'allowed_origins.*' => ['string', 'max:255', 'regex:/^https?:\/\/[^\s\/]+$/i'],
            'field_mapping' => ['nullable', 'array', 'max:100'],
            'field_mapping.*' => ['string', 'max:100'],
            'default_product_line' => ['nullable', 'string', 'max:100'],
        ];
    }
}
