<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            collect($this->resource->getAttributes())->except(['deleted_at', 'signature_data'])->all(),
            [
                'invoice_id' => $this->invoice_id,
            ]
        );
    }
}
