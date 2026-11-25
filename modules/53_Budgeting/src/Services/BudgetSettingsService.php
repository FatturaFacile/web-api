<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Models\BudgetSetting;

/**
 * The traffic light thresholds and the override validity of a company.
 */
final readonly class BudgetSettingsService
{
    public function get(string $tenantId): BudgetSetting
    {
        return BudgetSetting::forTenant($tenantId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $tenantId, array $data): BudgetSetting
    {
        $current = $this->get($tenantId);
        $amber = (string) ($data['amber_threshold_percent'] ?? $current->amber_threshold_percent);
        $red = (string) ($data['red_threshold_percent'] ?? $current->red_threshold_percent);

        if (bccomp($amber, $red, 2) > 0) {
            throw ValidationException::withMessages(['amber_threshold_percent' => [__('budgeting_errors.settings.thresholds')]]);
        }

        return BudgetSetting::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'amber_threshold_percent' => $amber,
                'red_threshold_percent' => $red,
                'override_validity_days' => (int) ($data['override_validity_days'] ?? $current->override_validity_days),
            ],
        )->refresh();
    }
}
