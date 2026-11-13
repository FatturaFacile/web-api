<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A contact or lead targeted by a campaign, with its delivery status (sent, or suppressed and why).
 *
 * @property string|null $contact_id
 * @property string|null $lead_id
 * @property string $status
 * @property string|null $suppression_reason
 * @property Carbon|null $sent_at
 * @property Carbon|null $first_associated_at
 */
final class CampaignMember extends BaseEnterpriseModel
{
    protected $table = 'crm_campaign_members';

    protected function casts(): array
    {
        return [
            'first_associated_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Calculated grid columns: the member's display name and email, taken from its contact or, else, its lead.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        $contactName = "COALESCE(NULLIF(contacts.company_name, ''), NULLIF(TRIM(COALESCE(contacts.first_name, '') || ' ' || COALESCE(contacts.last_name, '')), ''))";
        $leadName = "COALESCE(NULLIF(crm_leads.company_name, ''), NULLIF(TRIM(COALESCE(crm_leads.first_name, '') || ' ' || COALESCE(crm_leads.last_name, '')), ''))";

        return [
            'name' => "COALESCE((SELECT {$contactName} FROM contacts WHERE contacts.id = crm_campaign_members.contact_id), (SELECT {$leadName} FROM crm_leads WHERE crm_leads.id = crm_campaign_members.lead_id))",
            'email' => 'COALESCE((SELECT contacts.email FROM contacts WHERE contacts.id = crm_campaign_members.contact_id), (SELECT crm_leads.email FROM crm_leads WHERE crm_leads.id = crm_campaign_members.lead_id))',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
