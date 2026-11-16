<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The send log row of a campaign: one contact or lead, the address used and what happened.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $campaign_id
 * @property string|null $contact_id
 * @property string|null $lead_id
 * @property string|null $address
 * @property string $status
 * @property string|null $suppression_reason
 * @property string|null $delivery_log_id
 * @property string|null $error
 * @property Carbon|null $sent_at
 */
final class CampaignRecipient extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['pending', 'sent', 'failed', 'suppressed'];

    protected $table = 'comm_campaign_recipients';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
