<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SubscriptionBilling\Models\BillingRun;

/**
 * A billing run and its outcome.
 *
 * @mixin BillingRun
 */
final class BillingRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'run_number' => $this->run_number,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'execution_date' => $this->execution_date?->toDateString(),
            'total_subscriptions_evaluated' => (int) $this->total_subscriptions_evaluated,
            'successful_invoices_count' => (int) $this->successful_invoices_count,
            'failed_invoices_count' => (int) $this->failed_invoices_count,
            'total_invoiced_amount' => (string) $this->getRawOriginal('total_invoiced_amount'),
            'status' => $this->status,
            'errors_log' => $this->errors_log,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
