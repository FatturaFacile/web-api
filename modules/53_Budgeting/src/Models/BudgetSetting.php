<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Per company settings of the budget control: the variance thresholds of the traffic light and how long an approved
 * override (deroga) stays valid when the approver does not set an expiry.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $amber_threshold_percent
 * @property string $red_threshold_percent
 * @property int $override_validity_days
 */
final class BudgetSetting extends BaseEnterpriseModel
{
    public const string DefaultAmber = '5.00';

    public const string DefaultRed = '10.00';

    public const int DefaultValidityDays = 30;

    protected $table = 'budget_settings';

    protected function casts(): array
    {
        return [
            'amber_threshold_percent' => 'decimal:2',
            'red_threshold_percent' => 'decimal:2',
            'override_validity_days' => 'integer',
        ];
    }

    public static function forTenant(string $tenantId): self
    {
        return self::query()->where('tenant_id', $tenantId)->first() ?? new self([
            'tenant_id' => $tenantId,
            'amber_threshold_percent' => self::DefaultAmber,
            'red_threshold_percent' => self::DefaultRed,
            'override_validity_days' => self::DefaultValidityDays,
        ]);
    }
}
