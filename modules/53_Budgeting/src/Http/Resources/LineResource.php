<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetLine;

/**
 * A budget line.
 *
 * @mixin BudgetLine
 */
final class LineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_id' => $this->version_id,
            'account_id' => $this->account_id,
            'cost_center_id' => $this->cost_center_id,
            'project_id' => $this->project_id,
            'period' => $this->period,
            'amount' => $this->amount,
            'source' => $this->source,
            'driver_id' => $this->driver_id,
            'notes' => $this->notes,
        ];
    }
}
