<?php

declare(strict_types=1);

namespace Modules\Fleet\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Fleet\Models\FleetDeadline;

/**
 * Compliance deadlines (road tax, insurance, inspection). An open deadline expires on its date; renewing it closes it and
 * opens the next one.
 */
final readonly class DeadlineService
{
    private const array Fields = ['deadline_type', 'provider', 'reference', 'expires_on', 'cost', 'currency', 'notes'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): FleetDeadline
    {
        return FleetDeadline::create([
            ...array_intersect_key($data, array_flip(self::Fields)),
            'tenant_id' => $tenantId,
            'vehicle_id' => $data['vehicle_id'],
            'status' => FleetDeadline::Open,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FleetDeadline $deadline, array $data): FleetDeadline
    {
        $deadline->update(array_intersect_key($data, array_flip(self::Fields)));

        return $deadline->refresh();
    }

    /**
     * Closes the deadline as renewed and opens the next one; unspecified values carry over.
     *
     * @param  array<string, mixed>  $data
     */
    public function renew(FleetDeadline $deadline, array $data): FleetDeadline
    {
        return DB::transaction(function () use ($deadline, $data): FleetDeadline {
            $locked = FleetDeadline::query()->whereKey($deadline->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== FleetDeadline::Open) {
                throw ValidationException::withMessages(['status' => [__('fleet_errors.deadline.not_open')]]);
            }

            if (Carbon::parse($data['expires_on'])->startOfDay()->lte($locked->expires_on->copy()->startOfDay())) {
                throw ValidationException::withMessages(['expires_on' => [__('fleet_errors.deadline.renewal_date', ['date' => $locked->expires_on->toDateString()])]]);
            }

            $locked->update(['status' => FleetDeadline::Renewed, 'renewed_at' => now()]);

            return FleetDeadline::create([
                'tenant_id' => $locked->tenant_id,
                'vehicle_id' => $locked->vehicle_id,
                'deadline_type' => $locked->deadline_type,
                'provider' => $data['provider'] ?? $locked->provider,
                'reference' => $data['reference'] ?? $locked->reference,
                'expires_on' => $data['expires_on'],
                'cost' => $data['cost'] ?? $locked->cost,
                'currency' => $locked->currency,
                'status' => FleetDeadline::Open,
                'notes' => $data['notes'] ?? null,
            ])->refresh();
        });
    }

    /**
     * Days from today to the expiry (negative once expired).
     */
    public static function daysToExpiry(FleetDeadline $deadline): int
    {
        return (int) Carbon::today()->diffInDays($deadline->expires_on->copy()->startOfDay(), false);
    }
}
