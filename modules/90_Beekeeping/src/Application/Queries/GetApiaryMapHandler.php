<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Services\ForagingOverlapService;
use Modules\Beekeeping\Services\WithdrawalGuard;

/**
 * Data of the GIS map: apiary pins with foraging radius, hive counts, withdrawal locks and the overlapping foraging circles.
 */
final class GetApiaryMapHandler
{
    public function __construct(private readonly ForagingOverlapService $overlaps, private readonly WithdrawalGuard $guard) {}

    /**
     * @return array{apiaries: list<array<string, mixed>>, overlaps: list<array<string, mixed>>}
     */
    public function handle(GetApiaryMapQuery $query): array
    {
        $apiaries = Apiary::query()->when($query->onlyActive, static fn ($builder) => $builder->where('is_active', true))->orderBy('code')->get();
        $counts = Hive::query()->whereIn('apiary_id', $apiaries->pluck('id'))->whereIn('status', ['active', 'orphaned', 'queenless_cell'])
            ->selectRaw('apiary_id, COUNT(*) as total')->groupBy('apiary_id')->pluck('total', 'apiary_id');
        $today = CarbonImmutable::today();
        $overlaps = $this->overlaps->overlaps($apiaries);
        $overlapCount = [];

        foreach ($overlaps as $pair) {
            $overlapCount[$pair['apiary_id']] = ($overlapCount[$pair['apiary_id']] ?? 0) + 1;
            $overlapCount[$pair['other_apiary_id']] = ($overlapCount[$pair['other_apiary_id']] ?? 0) + 1;
        }

        return [
            'apiaries' => $apiaries->map(fn (Apiary $apiary): array => [
                'id' => $apiary->id,
                'code' => $apiary->code,
                'name' => $apiary->name,
                'country' => $apiary->country,
                'apiary_type' => $apiary->apiary_type,
                'latitude' => $apiary->latitude === null ? null : (float) $apiary->latitude,
                'longitude' => $apiary->longitude === null ? null : (float) $apiary->longitude,
                'foraging_radius_m' => $apiary->foraging_radius_m,
                'hive_count' => (int) ($counts[$apiary->id] ?? 0),
                'locked_hives' => count($this->guard->lockedHives($apiary->id, $today)),
                'overlap_count' => $overlapCount[$apiary->id] ?? 0,
            ])->all(),
            'overlaps' => $overlaps,
        ];
    }
}
