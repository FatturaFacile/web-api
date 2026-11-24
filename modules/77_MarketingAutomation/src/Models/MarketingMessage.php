<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mod 155 Marketing Automation — MarketingMessage.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $campaign_id
 * @property string $contact_id
 * @property string $token
 * @property string $channel
 * @property string $recipient
 * @property string|null $variant
 * @property string $locale
 * @property string $status
 * @property string|null $provider_reference
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $first_opened_at
 * @property Carbon|null $first_clicked_at
 * @property Carbon|null $bounced_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MarketingMessage extends BaseEnterpriseModel
{
    protected $table = 'ma_messages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'first_clicked_at' => 'datetime',
            'bounced_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
