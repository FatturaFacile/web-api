<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditDunningStep;

/**
 * A step of a dunning strategy.
 *
 * @mixin CreditDunningStep
 */
final class DunningStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'step_order' => $this->step_order,
            'name' => $this->name,
            'days_after_due' => $this->days_after_due,
            'channel' => $this->channel,
            'template_key' => $this->template_key,
            'fee_amount' => $this->fee_amount,
            'escalates' => $this->escalates,
        ];
    }
}
