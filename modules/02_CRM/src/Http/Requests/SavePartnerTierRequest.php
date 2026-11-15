<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * A partner program tier.
 */
final class SavePartnerTierRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unique = Rule::unique('crm_partner_tiers', 'code')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereNull('deleted_at');

        return $this->forMethod([
            'code' => ['required', 'string', 'max:40', $this->isMethod('PUT') ? $unique->ignore($this->route('id')) : $unique],
            'name' => ['required', 'string', 'max:100'],
            'rank' => ['required', 'integer', 'min:1', 'max:99'],
            'min_annual_revenue' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'mdf_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
    }
}
