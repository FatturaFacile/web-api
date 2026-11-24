<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Mod 155 Marketing Automation — Enrollment.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $campaign_id
 * @property string $contact_id
 * @property string|null $current_step_key
 * @property string $status
 * @property string|null $variant
 * @property Carbon|null $next_run_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Enrollment extends BaseEnterpriseModel
{
    protected $table = 'ma_enrollments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
        ];
    }
}
