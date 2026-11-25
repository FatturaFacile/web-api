<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The liquidity planning settings of the tenant.
 */
final class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jurisdiction' => ['required', Rule::in(['CH', 'IT'])],
            'share_capital' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'legal_minimum_capital' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'minimum_liquidity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'dscr_critical_threshold' => ['required', 'numeric', 'min:0', 'max:100'],
            'dscr_warning_threshold' => ['required', 'numeric', 'min:0', 'max:100', 'gte:dscr_critical_threshold'],
            'max_pfn_ebitda' => ['required', 'numeric', 'min:0', 'max:1000'],
            'ebitda_override' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'net_equity_override' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'alert_cooldown_hours' => ['required', 'integer', 'between:1,720'],
            'notify_emails' => ['nullable', 'array', 'max:20'],
            'notify_emails.*' => ['email:rfc', 'max:190'],
            'email_alerts_enabled' => ['required', 'boolean'],
            'collection_behavior_days' => ['required', 'integer', 'between:0,180'],
            'payroll_monthly_override' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'payroll_pay_day' => ['required', 'integer', 'between:1,28'],
            'tax_monthly_estimate' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }
}
