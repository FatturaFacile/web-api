<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * A sales quota of a user over a period.
 */
final class SaveSalesQuotaRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'user_id' => ['required', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())],
            'territory_id' => ['nullable', 'uuid', $this->ownRow('crm_territories')],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'target_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
