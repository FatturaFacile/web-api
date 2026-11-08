<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\RevenueContract;

/**
 * The IFRS 15 revenue contract of a subscription, with its obligations and slices when loaded.
 *
 * @mixin RevenueContract
 */
final class RevenueContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'contract_number' => $this->contract_number,
            'subscription_id' => $this->subscription_id,
            'total_transaction_price' => (string) $this->getRawOriginal('total_transaction_price'),
            'currency' => $this->currency,
            'inception_date' => $this->inception_date?->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'status' => $this->status,
            'performance_obligations' => $this->whenLoaded('performanceObligations', fn (): array => $this->performanceObligations->map(fn (PerformanceObligation $pob): array => [
                'id' => $pob->id,
                'pob_code' => $pob->pob_code,
                'description' => $pob->description,
                'recognition_method' => $pob->recognition_method,
                'source_document_type' => $pob->source_document_type,
                'source_document_id' => $pob->source_document_id,
                'allocated_transaction_price' => (string) $pob->getRawOriginal('allocated_transaction_price'),
                'recognized_revenue_to_date' => (string) $pob->getRawOriginal('recognized_revenue_to_date'),
                'deferred_revenue_balance' => (string) $pob->getRawOriginal('deferred_revenue_balance'),
                'start_date' => $pob->start_date?->toDateString(),
                'end_date' => $pob->end_date?->toDateString(),
                'status' => $pob->status,
                'schedules' => $pob->relationLoaded('revenueSchedules') ? RevenueScheduleResource::collection($pob->revenueSchedules)->resolve() : [],
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
