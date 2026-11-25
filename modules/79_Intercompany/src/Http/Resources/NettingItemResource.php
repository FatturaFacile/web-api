<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\NettingItem;

/**
 * @mixin NettingItem
 */
final class NettingItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'transaction_id' => $this->transaction_id,
            'side' => $this->side,
            'document_number' => $this->document_number,
            'currency' => $this->currency,
            'open_amount' => (string) $this->open_amount,
            'applied_amount' => (string) $this->applied_amount,
            'fx_rate' => (string) $this->fx_rate,
            'variance' => (string) $this->variance,
        ];
    }
}
