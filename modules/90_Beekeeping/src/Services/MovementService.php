<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Beekeeping\Exceptions\BeekeepingException;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\NomadismMovement;

/**
 * Nomadism (transhumance) and sale / purchase movements with their accompanying document (Allegato C BDA, Begleitdokument / Verkehrsschein).
 */
final class MovementService
{
    private const array Transitions = [
        'planned' => ['in_transit', 'cancelled'],
        'in_transit' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): NomadismMovement
    {
        $kind = (string) $data['kind'];

        if ($kind === 'nomadism' && empty($data['destination_apiary_id']) && empty($data['destination_label'])) {
            throw new BeekeepingException('A nomadism movement needs a destination.', 'movement_destination_required');
        }

        if ($kind === 'nomadism' && empty($data['target_flora'])) {
            throw new BeekeepingException('A nomadism movement needs the target flora.', 'movement_flora_required');
        }

        if (in_array($kind, ['sale', 'purchase'], true) && empty($data['counterpart'])) {
            throw new BeekeepingException('A sale or purchase needs the counterpart.', 'movement_counterpart_required');
        }

        if (($data['destination_apiary_id'] ?? null) === $data['origin_apiary_id']) {
            throw new BeekeepingException('Origin and destination are the same apiary.', 'movement_same_apiary');
        }

        if ($kind !== 'purchase' && ($data['subject'] ?? 'hives') !== 'queens') {
            $available = Hive::query()->where('apiary_id', $data['origin_apiary_id'])->whereIn('status', ['active', 'orphaned', 'queenless_cell'])->count();

            if ((int) $data['quantity'] > $available) {
                throw new BeekeepingException('The origin apiary does not hold that many hives.', 'movement_quantity_exceeds_hives', 422, ['available' => $available]);
            }
        }

        return DB::transaction(function () use ($data): NomadismMovement {
            $movement = new NomadismMovement(array_merge($data, ['document_number' => $this->nextDocumentNumber((string) $data['departure_date'])]));
            $movement->save();

            return $movement;
        });
    }

    public function transition(NomadismMovement $movement, string $status, ?string $arrivalDate = null): NomadismMovement
    {
        if (! in_array($status, self::Transitions[$movement->status] ?? [], true)) {
            throw new BeekeepingException('The movement cannot change to that status.', 'movement_invalid_transition');
        }

        $movement->status = $status;

        if ($status === 'completed') {
            $movement->arrival_date = Carbon::parse($arrivalDate ?? now()->toDateString());
        }

        $movement->save();

        return $movement;
    }

    private function nextDocumentNumber(string $departureDate): string
    {
        $year = (int) substr($departureDate, 0, 4);
        $count = NomadismMovement::query()->withTrashed()->where('document_number', 'like', "MOV-{$year}-%")->count();

        return sprintf('MOV-%d-%04d', $year, $count + 1);
    }
}
