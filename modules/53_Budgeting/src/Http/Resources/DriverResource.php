<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetDriver;

/**
 * A planning driver with the annual amount it generates (quantity times rate).
 *
 * @mixin BudgetDriver
 */
final class DriverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_id' => $this->version_id,
            'name' => $this->name,
            'account_id' => $this->account_id,
            'cost_center_id' => $this->cost_center_id,
            'project_id' => $this->project_id,
            'quantity' => $this->quantity,
            'rate' => $this->rate,
            'total_amount' => bcround(bcmul((string) $this->quantity, (string) $this->rate, 6), 2),
            'spread_method' => $this->spread_method,
            'percentages' => $this->percentages,
            'start_period' => $this->start_period,
            'end_period' => $this->end_period,
            'is_active' => $this->is_active,
        ];
    }
}
