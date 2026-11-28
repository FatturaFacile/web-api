<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\VarroaTreatment;

/**
 * The hard block of the veterinary register: while a hive (or its whole apiary) is inside the withdrawal period of an anti-varroa
 * treatment (treatment date <= date < withdrawal end date) no honey super may be placed on it and no honey may be harvested or extracted
 * from it.
 */
final class WithdrawalGuard
{
    /**
     * The hives of an apiary locked on a date, with the treatment that locks each (the one ending last wins).
     *
     * @param  list<string>|null  $onlyHiveIds  restrict the check to these hives
     * @return array<string, VarroaTreatment> hive id => locking treatment
     */
    public function lockedHives(string $apiaryId, CarbonImmutable $date, ?array $onlyHiveIds = null): array
    {
        /** @var Collection<int, VarroaTreatment> $treatments */
        $treatments = VarroaTreatment::query()
            ->where('apiary_id', $apiaryId)
            ->whereDate('treatment_date', '<=', $date->toDateString())
            ->whereDate('withdrawal_end_date', '>', $date->toDateString())
            ->get();

        if ($treatments->isEmpty()) {
            return [];
        }

        $hiveQuery = Hive::query()->where('apiary_id', $apiaryId)->whereNotIn('status', ['dead', 'sold', 'united']);

        if ($onlyHiveIds !== null) {
            $hiveQuery->whereIn('id', $onlyHiveIds);
        }

        $locked = [];

        foreach ($hiveQuery->pluck('id') as $hiveId) {
            foreach ($treatments as $treatment) {
                $covers = $treatment->whole_apiary || in_array($hiveId, $treatment->hive_ids ?? [], true);

                if ($covers && (! isset($locked[$hiveId]) || $treatment->withdrawal_end_date->greaterThan($locked[$hiveId]->withdrawal_end_date))) {
                    $locked[(string) $hiveId] = $treatment;
                }
            }
        }

        return $locked;
    }

    public function assertCanPlaceSupers(Hive $hive, ?CarbonImmutable $date = null): void
    {
        $date ??= CarbonImmutable::today();
        $lock = $this->lockedHives($hive->apiary_id, $date, [$hive->id])[$hive->id] ?? null;

        if ($lock !== null) {
            throw new BeekeepingException('Honey supers cannot be placed during the withdrawal period.', 'supers_blocked_by_withdrawal', 422, [
                'hive' => $hive->code,
                'until' => $lock->withdrawal_end_date->toDateString(),
                'product' => $lock->product,
            ]);
        }
    }

    /**
     * @param  list<string>|null  $onlyHiveIds
     */
    public function assertCanHarvest(string $apiaryId, CarbonImmutable $date, ?array $onlyHiveIds = null): void
    {
        $locked = $this->lockedHives($apiaryId, $date, $onlyHiveIds);

        if ($locked !== []) {
            $codes = Hive::query()->whereIn('id', array_keys($locked))->orderBy('code')->pluck('code')->all();
            $until = collect($locked)->map(static fn (VarroaTreatment $treatment): string => $treatment->withdrawal_end_date->toDateString())->max();

            throw new BeekeepingException('Honey cannot be harvested during the withdrawal period.', 'harvest_blocked_by_withdrawal', 422, [
                'hives' => implode(', ', $codes),
                'until' => (string) $until,
            ]);
        }
    }
}
