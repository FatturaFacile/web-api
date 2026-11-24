<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\DiscountThreshold;

/**
 * A discount approval threshold: from which total discount a role must approve the quote.
 */
final class SaveDiscountThresholdRequest extends CrmExtensionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unique = Rule::unique('crm_discount_thresholds', 'from_discount_percent')->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereNull('deleted_at');

        return $this->forMethod([
            'from_discount_percent' => ['required', 'numeric', 'min:0', 'max:100', $this->isMethod('PUT') ? $unique->ignore($this->route('id')) : $unique],
            'approver_role' => ['required', Rule::in(DiscountThreshold::ApproverRoles)],
        ]);
    }
}
