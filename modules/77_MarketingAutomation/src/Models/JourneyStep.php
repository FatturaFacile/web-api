<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Mod 155 Marketing Automation — JourneyStep.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $campaign_id
 * @property string $step_key
 * @property string $type
 * @property array<string, mixed>|null $config
 * @property string|null $next_step_key
 * @property string|null $true_step_key
 * @property string|null $false_step_key
 * @property bool $is_entry
 * @property array<string, mixed>|null $config
 * @property int $pos_x
 * @property int $pos_y
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class JourneyStep extends BaseEnterpriseModel
{
    protected $table = 'ma_journey_steps';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_entry' => 'boolean',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
        ];
    }
}
