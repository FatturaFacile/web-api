<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Transportation\Models\TmsTariffBand;

/**
 * Tariff bands. Two bands of the same table that serve the same zone pair never overlap in both their weight range and
 * their distance range (lower bound included, upper bound excluded, an empty upper bound is open ended): the rate of a
 * shipment must never be ambiguous.
 */
final class TariffBandService extends TransportationCrudService
{
    protected function modelClass(): string
    {
        return TmsTariffBand::class;
    }

    public function create(string $tenantId, array $data): Model
    {
        return DB::transaction(function () use ($tenantId, $data): Model {
            TransactionLock::acquire("tms:bands:{$data['tariff_table_id']}");

            return parent::create($tenantId, $data);
        });
    }

    public function update(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            TransactionLock::acquire('tms:bands:'.$record->getAttribute('tariff_table_id'));

            return parent::update($record, $data);
        });
    }

    protected function prepare(string $tenantId, array $data, ?Model $existing): array
    {
        $merged = [
            ...($existing?->only(['tariff_table_id', 'origin_zone', 'destination_zone', 'weight_from_kg', 'weight_to_kg', 'distance_from_km', 'distance_to_km']) ?? []),
            ...$data,
        ];

        $overlapping = TmsTariffBand::query()
            ->where('tenant_id', $tenantId)
            ->where('tariff_table_id', $merged['tariff_table_id'])
            ->when($existing !== null, static fn ($query) => $query->where('id', '!=', $existing?->id))
            ->get()
            ->first(fn (TmsTariffBand $band): bool => ($band->origin_zone ?? null) === ($merged['origin_zone'] ?? null)
                && ($band->destination_zone ?? null) === ($merged['destination_zone'] ?? null)
                && $this->overlaps((string) $band->weight_from_kg, $band->weight_to_kg, (string) ($merged['weight_from_kg'] ?? '0'), $merged['weight_to_kg'] ?? null, 3)
                && $this->overlaps((string) $band->distance_from_km, $band->distance_to_km, (string) ($merged['distance_from_km'] ?? '0'), $merged['distance_to_km'] ?? null, 2));

        if ($overlapping !== null) {
            throw ValidationException::withMessages(['weight_from_kg' => [__('transportation_errors.tariff.band_overlap', [
                'from' => (string) $overlapping->weight_from_kg,
                'to' => $overlapping->weight_to_kg !== null ? (string) $overlapping->weight_to_kg : '∞',
            ])]]);
        }

        return $data;
    }

    /**
     * Whether [fromA, toA) and [fromB, toB) share a value; an empty upper bound is open ended.
     */
    private function overlaps(string $fromA, mixed $toA, string $fromB, mixed $toB, int $scale): bool
    {
        $aBeforeEndOfB = $toB === null || bccomp($fromA, (string) $toB, $scale) < 0;
        $bBeforeEndOfA = $toA === null || bccomp($fromB, (string) $toA, $scale) < 0;

        return $aBeforeEndOfB && $bBeforeEndOfA;
    }
}
