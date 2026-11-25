<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

use Illuminate\Database\Eloquent\Collection;
use Modules\ExpenseManagement\Models\AciVehicleRate;

final class GetAciVehicleRatesHandler
{
    /**
     * @return Collection<int, AciVehicleRate>
     */
    public function handle(GetAciVehicleRatesQuery $query): Collection
    {
        return AciVehicleRate::query()
            ->when($query->source !== null, fn ($rates) => $rates->where('source', $query->source))
            ->when($query->vehicleCode !== null, fn ($rates) => $rates->where('vehicle_code', $query->vehicleCode))
            ->when($query->fuel !== null, fn ($rates) => $rates->where('fuel', $query->fuel))
            ->when($query->vehicleClass !== null, fn ($rates) => $rates->where('vehicle_class', $query->vehicleClass))
            ->when($query->search !== null && $query->search !== '', function ($rates) use ($query): void {
                $term = '%'.mb_strtolower((string) $query->search).'%';
                $rates->where(fn ($inner) => $inner->whereRaw('LOWER(make_model) LIKE ?', [$term])->orWhereRaw('LOWER(vehicle_code) LIKE ?', [$term]));
            })
            ->when($query->onDate !== null, fn ($rates) => $rates
                ->whereDate('valid_from', '<=', $query->onDate)
                ->where(fn ($inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $query->onDate)))
            ->orderBy('make_model')
            ->orderBy('annual_km_bracket')
            ->orderByDesc('valid_from')
            ->limit(500)
            ->get();
    }
}
