<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditCollectionAction;

/**
 * An action done on a collection case.
 *
 * @mixin CreditCollectionAction
 */
final class CaseActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'case_id' => $this->case_id,
            'step_id' => $this->step_id,
            'action_type' => $this->action_type,
            'channel' => $this->channel,
            'performed_on' => $this->performed_on->toDateString(),
            'performed_by' => $this->performed_by,
            'fee_amount' => $this->fee_amount,
            'outcome' => $this->outcome,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
