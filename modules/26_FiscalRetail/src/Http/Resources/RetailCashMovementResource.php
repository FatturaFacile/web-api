<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FiscalRetail\Models\RetailCashMovement;

/**
 * A pay-in, pay-out or safe drop of the cash drawer.
 *
 * @mixin RetailCashMovement
 */
final class RetailCashMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pos_session_id' => $this->pos_session_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
