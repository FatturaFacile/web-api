<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adds one contact or one lead of the active tenant, or a whole segment, to a campaign.
 */
final class StoreCampaignMemberRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'uuid', 'required_without_all:lead_id,segment_id', 'prohibits:lead_id,segment_id', $this->tenantOwns('contacts')],
            'lead_id' => ['nullable', 'uuid', 'required_without_all:contact_id,segment_id', 'prohibits:contact_id,segment_id', $this->tenantOwns('crm_leads')],
            'segment_id' => ['nullable', 'uuid', 'required_without_all:contact_id,lead_id', 'prohibits:contact_id,lead_id', $this->tenantOwns('crm_segments')],
        ];
    }
}
