<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The liquidity planning settings of one tenant: jurisdiction (CO 725 for Switzerland, Art. 2446/2447 c.c. for Italy), share
 * capital, thresholds of the early-warning indicators and of the alert notifications.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $jurisdiction
 * @property string|null $share_capital
 * @property string|null $legal_minimum_capital
 * @property string $minimum_liquidity
 * @property string $dscr_critical_threshold
 * @property string $dscr_warning_threshold
 * @property string $max_pfn_ebitda
 * @property string|null $ebitda_override
 * @property string|null $net_equity_override
 * @property int $alert_cooldown_hours
 * @property list<string>|null $notify_emails
 * @property bool $email_alerts_enabled
 * @property int $collection_behavior_days
 * @property string|null $payroll_monthly_override
 * @property int $payroll_pay_day
 * @property string|null $tax_monthly_estimate
 */
final class LiquiditySettings extends BaseEnterpriseModel
{
    protected $table = 'lp_settings';

    protected function casts(): array
    {
        return [
            'share_capital' => 'decimal:2',
            'legal_minimum_capital' => 'decimal:2',
            'minimum_liquidity' => 'decimal:2',
            'dscr_critical_threshold' => 'decimal:2',
            'dscr_warning_threshold' => 'decimal:2',
            'max_pfn_ebitda' => 'decimal:2',
            'ebitda_override' => 'decimal:2',
            'net_equity_override' => 'decimal:2',
            'alert_cooldown_hours' => 'integer',
            'notify_emails' => 'array',
            'email_alerts_enabled' => 'boolean',
            'collection_behavior_days' => 'integer',
            'payroll_monthly_override' => 'decimal:2',
            'payroll_pay_day' => 'integer',
            'tax_monthly_estimate' => 'decimal:2',
        ];
    }

    /**
     * The settings of the current tenant, with the defaults of the country when nothing is stored yet.
     */
    public static function current(?string $country = null): self
    {
        return self::query()->first() ?? new self([
            'jurisdiction' => $country === 'CH' ? 'CH' : 'IT',
            'minimum_liquidity' => '0.00',
            'dscr_critical_threshold' => '1.00',
            'dscr_warning_threshold' => '1.20',
            'max_pfn_ebitda' => '3.50',
            'alert_cooldown_hours' => 24,
            'notify_emails' => [],
            'email_alerts_enabled' => true,
            'collection_behavior_days' => 0,
            'payroll_pay_day' => 27,
        ]);
    }
}
