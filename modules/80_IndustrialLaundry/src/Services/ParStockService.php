<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Domain\Enums\ScanDirection;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\ParContract;
use Modules\IndustrialLaundry\Models\ParContractLine;
use Modules\IndustrialLaundry\Models\ScanSession;
use Modules\IndustrialLaundry\Support\Money;

/**
 * Par level against circulating stock, per customer (hotel, restaurant, clinic, nursing home): how many garments the contract
 * promises, how many exist, where they are, the weight and pieces of the month and the bill the month would be so far.
 */
final class ParStockService
{
    public function __construct(private readonly MonthlyBillingService $billing) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function overview(?string $segment, ?string $customerId, ?Carbon $asOf = null, bool $withPreview = true): array
    {
        $asOf ??= Carbon::now();
        $start = $asOf->copy()->startOfMonth();
        $end = $asOf->copy()->endOfMonth();

        return ParContract::query()
            ->with(['lines', 'customer:id,company_name,first_name,last_name,country'])
            ->where('is_active', true)
            ->when($segment !== null, fn (Builder $query) => $query->where('customer_segment', $segment))
            ->when($customerId !== null, fn (Builder $query) => $query->where('customer_id', $customerId))
            ->orderBy('code')
            ->get()
            ->map(fn (ParContract $contract): array => $this->row($contract, $start, $end, $withPreview))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ParContract $contract, Carbon $start, Carbon $end, bool $withPreview): array
    {
        $garments = Garment::query()->where('assigned_customer_id', $contract->customer_id)->where('current_lifecycle_state', '!=', LifecycleState::CondemnedRag->value)->get();
        $sessions = ScanSession::query()->where('contact_id', $contract->customer_id)->whereBetween('scanned_at', [$start, $end])->get();
        $weigh = fn (ScanDirection $direction): string => Money::weight(Money::sum($sessions->filter(fn (ScanSession $session): bool => $session->direction === $direction)->pluck('net_weight_kg')));
        $customer = $contract->customer;

        $lines = $contract->lines->sortBy(fn (ParContractLine $line): string => $line->article_type->value)->map(function (ParContractLine $line) use ($garments): array {
            $ofType = $garments->filter(fn (Garment $garment): bool => $garment->article_type === $line->article_type);
            $atCustomer = $ofType->where('current_lifecycle_state', LifecycleState::DeliveredToCustomer)->count();
            $inMending = $ofType->where('current_lifecycle_state', LifecycleState::InMending)->count();

            return [
                'article_type' => $line->article_type->value,
                'par_level' => $line->circulating_stock_qty,
                'circulating' => $ofType->count(),
                'at_customer' => $atCustomer,
                'in_laundry' => $ofType->count() - $atCustomer - $inMending,
                'in_mending' => $inMending,
                'gap' => $line->circulating_stock_qty - $ofType->count(),
            ];
        })->values()->all();

        $preview = $withPreview ? $this->billing->preview($contract, $start, $end) : null;

        return [
            'contract_id' => (string) $contract->id,
            'contract_code' => $contract->code,
            'customer_id' => (string) $contract->customer_id,
            'customer_name' => $customer === null ? null : ($customer->company_name ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))),
            'customer_segment' => $contract->customer_segment->value,
            'billing_model' => $contract->billing_model->value,
            'currency' => $contract->currency,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'lines' => $lines,
            'par_total' => array_sum(array_column($lines, 'par_level')),
            'circulating_total' => array_sum(array_column($lines, 'circulating')),
            'pieces_delivered' => $preview['washed_pieces'] ?? null,
            'net_kg_dirty' => $weigh(ScanDirection::InboundSoil),
            'net_kg_clean' => $weigh(ScanDirection::OutboundClean),
            'billing_preview' => $preview === null ? null : [
                'wash_amount' => $preview['wash_amount'],
                'minimum_top_up_amount' => $preview['minimum_top_up_amount'],
                'rental_amount' => $preview['rental_amount'],
                'unreturned_amount' => $preview['unreturned_amount'],
                'subtotal' => $preview['subtotal'],
                'tax_amount' => $preview['tax_amount'],
                'total' => $preview['total'],
            ],
        ];
    }
}
