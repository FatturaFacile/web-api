<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetSetting;

/**
 * The budget control settings of the company.
 *
 * @mixin BudgetSetting
 */
final class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'amber_threshold_percent' => $this->amber_threshold_percent,
            'red_threshold_percent' => $this->red_threshold_percent,
            'override_validity_days' => $this->override_validity_days,
        ];
    }
}
