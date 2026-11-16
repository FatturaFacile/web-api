<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * A sales territory with its matching criteria.
 */
final class SaveSalesTerritoryRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unique = Rule::unique('crm_territories', 'code')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereNull('deleted_at');

        return $this->forMethod([
            'code' => ['required', 'string', 'max:40', $this->isMethod('PUT') ? $unique->ignore($this->route('id')) : $unique],
            'name' => ['required', 'string', 'max:150'],
            'owner_user_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())],
            'criteria' => ['nullable', 'array:countries,industries,tiers'],
            'criteria.countries' => ['sometimes', 'array'],
            'criteria.countries.*' => ['string', 'size:2'],
            'criteria.industries' => ['sometimes', 'array'],
            'criteria.industries.*' => ['string', 'max:100'],
            'criteria.tiers' => ['sometimes', 'array'],
            'criteria.tiers.*' => ['string', 'max:30'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
