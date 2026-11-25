<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\PurchaseRecord;

/**
 * @mixin PurchaseRecord
 */
final class PurchaseRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'period' => $this->period,
            'purchased_amount' => $this->purchased_amount,
            'required_amount' => $this->required_amount,
            'compliant' => $this->compliant,
        ];
    }
}
