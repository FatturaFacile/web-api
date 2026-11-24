<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Mod 155 Marketing Automation — MarketingConsent.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $contact_id
 * @property bool $marketing_opt_in
 * @property bool $profiling_opt_in
 * @property bool $soft_opt_in_eligible
 * @property bool $double_opt_in_confirmed
 * @property array<string, bool>|null $channel_preferences
 * @property string $locale
 * @property Carbon|null $unsubscribed_at
 * @property array<string, mixed>|null $channel_preferences
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MarketingConsent extends BaseEnterpriseModel
{
    protected $table = 'ma_consents';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marketing_opt_in' => 'boolean',
            'profiling_opt_in' => 'boolean',
            'soft_opt_in_eligible' => 'boolean',
            'double_opt_in_confirmed' => 'boolean',
            'channel_preferences' => 'array',
            'unsubscribed_at' => 'datetime',
        ];
    }
}
