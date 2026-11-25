<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\ParContract;

/**
 * Dormant garments: delivered to the customer and not sent back to the wash for more than the threshold (60 days by default, or
 * the unreturned threshold of the customer's contract). They are the next unreturned garments the monthly bill charges.
 */
final class DormantGarmentService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $customerId, ?int $days, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();
        $default = (int) config('industrial_laundry.dormant_days', 60);
        $thresholds = ParContract::query()->where('is_active', true)->get()->groupBy('customer_id')->map(fn ($contracts): int => (int) $contracts->min('unreturned_threshold_days'))->all();
        $shortest = $days ?? min([$default, ...array_values($thresholds)]);

        $garments = Garment::query()
            ->with('customer:id,company_name,first_name,last_name')
            ->where('current_lifecycle_state', LifecycleState::DeliveredToCustomer->value)
            ->when($customerId !== null, fn (Builder $query) => $query->where('assigned_customer_id', $customerId))
            ->where(fn (Builder $query) => $query->where('last_delivered_at', '<=', $asOf->copy()->subDays($shortest))->orWhere(fn (Builder $inner) => $inner->whereNull('last_delivered_at')->where('created_at', '<=', $asOf->copy()->subDays($shortest))))
            ->orderBy('last_delivered_at')
            ->orderBy('epc_uid_rfid_hex')
            ->get();

        $rows = [];

        foreach ($garments as $garment) {
            $since = $garment->last_delivered_at ?? $garment->last_scanned_at ?? $garment->created_at;
            $threshold = $days ?? ($garment->assigned_customer_id !== null ? ($thresholds[$garment->assigned_customer_id] ?? $default) : $default);
            $dormantDays = (int) $since->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay());

            if ($dormantDays < $threshold) {
                continue;
            }

            $customer = $garment->customer;
            $rows[] = [
                'id' => (string) $garment->id,
                'epc_uid_rfid_hex' => $garment->epc_uid_rfid_hex,
                'article_type' => $garment->article_type->value,
                'assigned_customer_id' => $garment->assigned_customer_id,
                'customer_name' => $customer === null ? null : ($customer->company_name ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))),
                'assigned_wearer_employee_name' => $garment->assigned_wearer_employee_name,
                'last_delivered_at' => $since->toIso8601String(),
                'dormant_days' => $dormantDays,
                'threshold_days' => $threshold,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => [$right['dormant_days'], $left['epc_uid_rfid_hex']] <=> [$left['dormant_days'], $right['epc_uid_rfid_hex']]);

        return $rows;
    }
}
