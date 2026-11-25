<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetActualVolume;

/**
 * The actual quantity of an account in a month.
 *
 * @mixin BudgetActualVolume
 */
final class ActualVolumeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'cost_center_id' => $this->cost_center_id,
            'project_id' => $this->project_id,
            'month' => $this->month,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
        ];
    }
}
