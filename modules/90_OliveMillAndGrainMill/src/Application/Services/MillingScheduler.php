<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Domain\Exceptions\OliveMillException;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

/**
 * Booking of the hopper slots: a milling line crushes one lot at a time, so two bookings of the same line cannot overlap.
 */
final class MillingScheduler
{
    public const int DEFAULT_DURATION_MINUTES = 60;

    /**
     * @throws OliveMillException slot_conflict
     */
    public function assertSlotFree(?string $line, Carbon $start, ?int $minutes, ?string $ignoreDeliveryId = null): void
    {
        if ($line === null || $line === '') {
            return;
        }

        $end = $start->copy()->addMinutes($minutes ?? self::DEFAULT_DURATION_MINUTES);

        $clash = OliveDelivery::query()
            ->where('hopper_line', $line)
            ->whereNotNull('scheduled_at')
            ->whereNotIn('status', [DeliveryStatus::Cancelled->value, DeliveryStatus::Milled->value, DeliveryStatus::Settled->value])
            ->when($ignoreDeliveryId, static fn ($query, string $id) => $query->where('id', '!=', $id))
            ->whereBetween('scheduled_at', [$start->copy()->subHours(24), $end])
            ->get()
            ->first(static function (OliveDelivery $other) use ($start, $end): bool {
                $otherStart = $other->scheduled_at;
                $otherEnd = $otherStart->copy()->addMinutes($other->estimated_duration_minutes ?? self::DEFAULT_DURATION_MINUTES);

                return $otherStart->lt($end) && $otherEnd->gt($start);
            });

        if ($clash !== null) {
            throw OliveMillException::because('slot_conflict', ['line' => $line, 'lot' => $clash->lot_code]);
        }
    }

    /**
     * Bookings of the period, in time order, optionally limited to one line.
     *
     * @return Collection<int, OliveDelivery>
     */
    public function bookings(Carbon $from, Carbon $to, ?string $line): Collection
    {
        return OliveDelivery::query()
            ->with('farmer:id,company_name,first_name,last_name')
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$from, $to])
            ->when($line, static fn ($query, string $value) => $query->where('hopper_line', $value))
            ->where('status', '!=', DeliveryStatus::Cancelled->value)
            ->orderBy('scheduled_at')
            ->get();
    }
}
