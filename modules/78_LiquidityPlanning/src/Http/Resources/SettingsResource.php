<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LiquidityPlanning\Models\LiquiditySettings;

/**
 * The liquidity planning settings of the tenant.
 *
 * @mixin LiquiditySettings
 */
final class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $money = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        return [
            'jurisdiction' => $this->jurisdiction,
            'share_capital' => $money($this->share_capital),
            'legal_minimum_capital' => $money($this->legal_minimum_capital),
            'minimum_liquidity' => (string) $this->minimum_liquidity,
            'dscr_critical_threshold' => (string) $this->dscr_critical_threshold,
            'dscr_warning_threshold' => (string) $this->dscr_warning_threshold,
            'max_pfn_ebitda' => (string) $this->max_pfn_ebitda,
            'ebitda_override' => $money($this->ebitda_override),
            'net_equity_override' => $money($this->net_equity_override),
            'alert_cooldown_hours' => (int) $this->alert_cooldown_hours,
            'notify_emails' => $this->notify_emails ?? [],
            'email_alerts_enabled' => (bool) $this->email_alerts_enabled,
            'collection_behavior_days' => (int) $this->collection_behavior_days,
            'payroll_monthly_override' => $money($this->payroll_monthly_override),
            'payroll_pay_day' => (int) $this->payroll_pay_day,
            'tax_monthly_estimate' => $money($this->tax_monthly_estimate),
        ];
    }
}
