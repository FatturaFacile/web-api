<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RevenueContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'contract_number' => $this->contract_number,
            'subscription_id' => $this->subscription_id,
            'total_transaction_price' => (float) $this->total_transaction_price,
            'currency' => $this->currency,
            'inception_date' => $this->inception_date?->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
