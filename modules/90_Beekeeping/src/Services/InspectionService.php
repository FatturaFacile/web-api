<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Beekeeping\Events\ColonyAlertRaised;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\Inspection;

/**
 * Records hive inspections: updates the colony figures of the hive, raises the automated alerts and refuses to place honey supers on a hive
 * inside a varroa withdrawal period.
 */
final class InspectionService
{
    public function __construct(private readonly WithdrawalGuard $guard, private readonly ColonyAlertService $alerts) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(Hive $hive, array $data, ?string $operatorId): Inspection
    {
        $inspectedAt = CarbonImmutable::parse((string) ($data['inspected_at'] ?? now()));
        $added = (int) ($data['supers_added'] ?? 0);

        if ($added > 0) {
            $this->guard->assertCanPlaceSupers($hive, $inspectedAt);
        }

        return DB::transaction(function () use ($hive, $data, $operatorId, $inspectedAt, $added): Inspection {
            $removed = (int) ($data['supers_removed'] ?? 0);
            $brood = (int) ($data['brood_frames'] ?? $hive->brood_frames);
            $stores = (int) ($data['store_frames'] ?? $hive->store_frames);
            $supers = max(0, $hive->supers_count + $added - $removed);
            $strength = isset($data['strength_score']) ? (int) $data['strength_score'] : $hive->strength_score;

            $alerts = $this->alerts->alerts([
                'brood_frames' => $brood,
                'supers_count' => $supers,
                'strength_score' => $strength,
                'swarm_cells_found' => (int) ($data['swarm_cells_found'] ?? 0),
                'swarm_cells_destroyed' => (int) ($data['swarm_cells_destroyed'] ?? 0),
                'queen_seen' => (bool) ($data['queen_seen'] ?? false),
                'eggs_present' => (bool) ($data['eggs_present'] ?? false),
                'larvae_present' => (bool) ($data['larvae_present'] ?? false),
                'queen_status' => $hive->queen_status,
                'queen_year' => $hive->queen_year,
            ], (int) $inspectedAt->year);

            $inspection = new Inspection(array_merge($data, [
                'hive_id' => $hive->id,
                'apiary_id' => $hive->apiary_id,
                'inspected_at' => $inspectedAt,
                'operator_id' => $operatorId,
                'brood_frames' => $brood,
                'store_frames' => $stores,
                'strength_score' => $strength,
                'alerts' => $alerts,
            ]));
            $inspection->save();

            $hive->brood_frames = $brood;
            $hive->store_frames = $stores;
            $hive->supers_count = $supers;
            $hive->strength_score = $strength;

            if (in_array('queenless', $alerts, true) && $hive->status === 'active') {
                $hive->status = 'orphaned';
                $hive->queen_status = 'missing';
            } elseif (($data['queen_seen'] ?? false) === true && $hive->status === 'orphaned') {
                $hive->status = 'active';
                $hive->queen_status = 'present_laying';
            }

            $hive->save();

            if ($alerts !== []) {
                event(new ColonyAlertRaised($hive->id, $inspection->id, $alerts));
            }

            return $inspection;
        });
    }

    /**
     * Inspects several hives of one apiary with the same observations (all or nothing).
     *
     * @param  list<string>  $hiveIds
     * @param  array<string, mixed>  $data
     * @return list<Inspection>
     */
    public function recordBatch(string $apiaryId, array $hiveIds, array $data, ?string $operatorId): array
    {
        return DB::transaction(function () use ($apiaryId, $hiveIds, $data, $operatorId): array {
            $inspections = [];

            foreach (Hive::query()->where('apiary_id', $apiaryId)->whereIn('id', $hiveIds)->orderBy('code')->get() as $hive) {
                $inspections[] = $this->record($hive, $data, $operatorId);
            }

            return $inspections;
        });
    }
}
