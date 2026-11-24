<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Mod 155 Marketing Automation — Suppression.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $contact_id
 * @property string $address
 * @property string $channel
 * @property string $reason
 * @property string|null $campaign_id
 * @property \Illuminate\Support\Carbon|null $suppressed_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Suppression extends BaseEnterpriseModel
{
    protected $table = 'ma_suppressions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
        ];
    }
}
