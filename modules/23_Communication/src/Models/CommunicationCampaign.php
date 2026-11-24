<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A one-to-many message on one channel sent to the members of a CRM segment, now or at a scheduled time.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $channel
 * @property string $purpose
 * @property string|null $segment_id
 * @property string|null $subject
 * @property string $body
 * @property string $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int $recipients_count
 * @property int $sent_count
 * @property int $failed_count
 * @property int $suppressed_count
 */
final class CommunicationCampaign extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['draft', 'scheduled', 'sending', 'completed', 'cancelled'];

    /** @var list<string> */
    public const array Purposes = ['marketing', 'newsletter'];

    protected $table = 'comm_campaigns';

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'recipients_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'suppressed_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'campaign_id');
    }
}
