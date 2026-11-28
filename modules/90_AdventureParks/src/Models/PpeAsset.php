<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\AdventureParks\Domain\Enums\InspectionOutcome;
use Modules\AdventureParks\Domain\Enums\InspectionType;
use Modules\AdventureParks\Domain\Enums\PpeCategory;
use Modules\AdventureParks\Domain\Enums\PpeSize;
use Modules\AdventureParks\Domain\Enums\PpeStatus;
use Modules\AdventureParks\Exceptions\AdventureParksException;

/**
 * A personal protective equipment item of the register (UNI EN 365): one row per physical item with its serial code, its life
 * (manufacturer lifespan, rental cycles, usage hours) and its inspections. The aggregate decides by itself whether it can be handed
 * out on a day, blocks itself when its life or its annual inspection runs out, and moves through the life cycle.
 *
 * @property string $serial_code
 * @property string $category
 * @property string $size
 * @property int|null $min_height_cm
 * @property int|null $max_height_cm
 * @property int|null $min_weight_kg
 * @property int|null $max_weight_kg
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $batch_number
 * @property Carbon|null $purchase_date
 * @property Carbon|null $first_use_date
 * @property Carbon $manufacturer_max_lifespan_date
 * @property int $rental_cycle_count
 * @property string $cumulative_usage_hours
 * @property Carbon|null $last_inspection_date
 * @property Carbon|null $next_inspection_due_date
 * @property string $status
 * @property string|null $notes
 */
final class PpeAsset extends BaseEnterpriseModel
{
    public const string REASON_LIFESPAN = 'LIFESPAN_EXPIRED';

    public const string REASON_INSPECTION = 'INSPECTION_OVERDUE';

    public const string REASON_STATUS = 'STATUS';

    protected $table = 'adv_ppe_assets';

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'first_use_date' => 'date',
            'manufacturer_max_lifespan_date' => 'date',
            'last_inspection_date' => 'date',
            'next_inspection_due_date' => 'date',
            'rental_cycle_count' => 'integer',
            'cumulative_usage_hours' => 'decimal:2',
            'min_height_cm' => 'integer',
            'max_height_cm' => 'integer',
            'min_weight_kg' => 'integer',
            'max_weight_kg' => 'integer',
        ];
    }

    /**
     * @return HasMany<PpeInspectionLog, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(PpeInspectionLog::class, 'ppe_asset_id');
    }

    public function statusEnum(): PpeStatus
    {
        return PpeStatus::from($this->status);
    }

    public function categoryEnum(): PpeCategory
    {
        return PpeCategory::from($this->category);
    }

    public function sizeEnum(): PpeSize
    {
        return PpeSize::from($this->size);
    }

    public function isLifespanExpiredOn(CarbonInterface $date): bool
    {
        return $date->copy()->startOfDay()->greaterThan($this->manufacturer_max_lifespan_date->copy()->startOfDay());
    }

    public function isInspectionOverdueOn(CarbonInterface $date): bool
    {
        return $this->next_inspection_due_date !== null
            && $date->copy()->startOfDay()->greaterThan($this->next_inspection_due_date->copy()->startOfDay());
    }

    /**
     * Why the item cannot be handed out on `$date`: the end of its life, an annual inspection (EN 365) that is overdue, or a status
     * other than AVAILABLE. Null when it can be assigned.
     */
    public function blockedReason(CarbonInterface $date): ?string
    {
        if ($this->isLifespanExpiredOn($date)) {
            return self::REASON_LIFESPAN;
        }

        if ($this->isInspectionOverdueOn($date)) {
            return self::REASON_INSPECTION;
        }

        return $this->statusEnum() === PpeStatus::Available ? null : self::REASON_STATUS;
    }

    public function isAssignableOn(CarbonInterface $date): bool
    {
        return $this->blockedReason($date) === null;
    }

    /**
     * Whether the item counts in the stock of a day: it is in the park (available or out with a visitor) and its life and its
     * inspection are valid that day. A harness that is out with a visitor is booked by that visitor ticket, not missing.
     */
    public function countsAsStockOn(CarbonInterface $date): bool
    {
        return in_array($this->statusEnum(), [PpeStatus::Available, PpeStatus::InUse], true)
            && ! $this->isLifespanExpiredOn($date)
            && ! $this->isInspectionOverdueOn($date);
    }

    /**
     * Moves an available item to EXPIRED_BLOCKED when its lifespan or its annual inspection ran out on `$date`.
     *
     * @return bool whether the status changed
     */
    public function syncStatus(CarbonInterface $date): bool
    {
        if (! in_array($this->statusEnum(), [PpeStatus::Available, PpeStatus::InUse], true)) {
            return false;
        }

        if (! $this->isLifespanExpiredOn($date) && ! $this->isInspectionOverdueOn($date)) {
            return false;
        }

        $this->status = PpeStatus::ExpiredBlocked->value;

        return true;
    }

    /**
     * Hands the item out. The blocking rules apply on the day of the hand-out.
     */
    public function assign(CarbonInterface $date): void
    {
        $reason = $this->blockedReason($date);

        if ($reason !== null) {
            throw $this->blockedException($reason);
        }

        $this->status = PpeStatus::InUse->value;
        $this->first_use_date ??= $date->copy()->startOfDay();
    }

    /**
     * Takes the item back: one more rental cycle, the hours of use added up, and the item is available again unless its life or its
     * inspection ran out in the meantime.
     */
    public function release(float $usageHours, CarbonInterface $date): void
    {
        $this->rental_cycle_count++;
        $this->cumulative_usage_hours = number_format((float) $this->cumulative_usage_hours + max(0.0, $usageHours), 2, '.', '');

        if ($this->statusEnum() === PpeStatus::InUse) {
            $this->status = PpeStatus::Available->value;
            $this->syncStatus($date);
        }
    }

    /**
     * Applies the outcome of an inspection. A failed inspection sends the item to maintenance; a passed annual inspection (EN 365)
     * unlocks it and moves the next due date a year ahead (or to the stated date). A passed inspection never revives an item past the
     * manufacturer lifespan or a decommissioned one.
     */
    public function applyInspection(InspectionType $type, InspectionOutcome $outcome, CarbonInterface $on, ?CarbonInterface $nextDue = null): void
    {
        $this->last_inspection_date = $on->copy()->startOfDay();

        if ($outcome === InspectionOutcome::Fail) {
            if ($this->statusEnum() !== PpeStatus::Decommissioned) {
                $this->status = PpeStatus::MaintenanceRequired->value;
            }

            return;
        }

        if ($type !== InspectionType::AnnualEn365) {
            return;
        }

        $this->next_inspection_due_date = ($nextDue ?? $on->copy()->addMonths((int) config('adventure_parks.annual_inspection_months', 12)))->copy()->startOfDay();

        if (in_array($this->statusEnum(), [PpeStatus::MaintenanceRequired, PpeStatus::ExpiredBlocked], true)) {
            $this->status = $this->isLifespanExpiredOn($on) ? PpeStatus::ExpiredBlocked->value : PpeStatus::Available->value;
        }
    }

    public function lifespanRemainingDays(CarbonInterface $today): int
    {
        return (int) $today->copy()->startOfDay()->diffInDays($this->manufacturer_max_lifespan_date->copy()->startOfDay(), false);
    }

    /**
     * Share of the life already used, from the first use (or the purchase) to the manufacturer limit, between 0 and 100.
     */
    public function lifespanPercentUsed(CarbonInterface $today): float
    {
        $start = $this->first_use_date ?? $this->purchase_date;

        if ($start === null) {
            return 0.0;
        }

        $total = $start->copy()->startOfDay()->diffInDays($this->manufacturer_max_lifespan_date->copy()->startOfDay(), false);

        if ($total <= 0) {
            return 100.0;
        }

        $used = $start->copy()->startOfDay()->diffInDays($today->copy()->startOfDay(), false);

        return round(max(0.0, min(100.0, $used / $total * 100)), 1);
    }

    public function blockedException(string $reason): AdventureParksException
    {
        return AdventureParksException::refuse('ppe_blocked', 'The PPE cannot be handed out: '.$reason.'.', [
            'serial_code' => $this->serial_code,
            'category' => $this->category,
            'reason' => $reason,
            'status' => $this->status,
        ]);
    }
}
