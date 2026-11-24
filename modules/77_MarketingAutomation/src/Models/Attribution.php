<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Mod 155 Marketing Automation — Attribution.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $campaign_id
 * @property string|null $message_id
 * @property string|null $contact_id
 * @property string $document_type
 * @property string $document_id
 * @property string $touch_model
 * @property string $currency
 * @property string $via
 * @property string $amount_net
 * @property string $amount_gross
 * @property string $gross_margin
 * @property string $collected_amount
 * @property \Illuminate\Support\Carbon|null $attributed_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Attribution extends BaseEnterpriseModel
{
    protected $table = 'ma_attributions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_net' => 'decimal:2',
            'amount_gross' => 'decimal:2',
            'gross_margin' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'attributed_at' => 'datetime',
        ];
    }
}
