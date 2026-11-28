<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\DairyCheeseManufacturing\Domain\Milk\MilkConversion;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Models\MilkTank;
use Modules\DairyCheeseManufacturing\Models\QualityTest;

/**
 * Daily raw milk intake of the breeders into the tanks.
 */
final class DeliveryService
{
    public function __construct(private readonly TankInventoryService $tanks) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, ?string $userId = null): MilkDelivery
    {
        return DB::transaction(function () use ($data, $userId): MilkDelivery {
            $breeder = Breeder::query()->findOrFail((string) $data['breeder_id']);
            $tank = MilkTank::query()->lockForUpdate()->findOrFail((string) $data['tank_id']);

            if (! $breeder->is_active) {
                throw new DairyCheeseException('The breeder is not active.', 'breeder_inactive');
            }

            if ($tank->status !== 'active') {
                throw new DairyCheeseException('The tank cannot receive milk.', 'tank_not_available');
            }

            $liters = round((float) $data['liters'], 3);
            $density = round((float) ($data['density'] ?? config('dairy_cheese.default_density', MilkConversion::DefaultDensity)), 4);
            $capacity = (float) $tank->capacity_liters;

            if ($capacity > 0 && $this->tanks->volume($tank->id)['liters'] + $liters > $capacity + 0.0005) {
                throw new DairyCheeseException('The tank capacity would be exceeded.', 'tank_capacity_exceeded');
            }

            $kg = MilkConversion::kg($liters, $density);
            $date = (string) $data['collection_date'];
            $positive = QualityTest::query()->where('breeder_id', $breeder->id)->whereDate('sample_date', $date)->where('inhibitors', 'positive')->exists();

            return MilkDelivery::query()->create([
                'breeder_id' => $breeder->id,
                'tank_id' => $tank->id,
                'route_code' => $data['route_code'] ?? null,
                'tanker_plate' => $data['tanker_plate'] ?? null,
                'collection_date' => $date,
                'session' => (string) $data['session'],
                'liters' => $liters,
                'density' => $density,
                'kg' => $kg,
                'remaining_liters' => $liters,
                'remaining_kg' => $kg,
                'temperature_c' => $data['temperature_c'] ?? null,
                'status' => $positive ? 'quarantined' : 'registered',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Registers every row on its own: a row that is refused does not stop the others.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: list<MilkDelivery>, rejected: list<array{index: int, errors: list<string>}>}
     */
    public function registerBulk(array $rows, ?string $userId = null): array
    {
        $created = [];
        $rejected = [];

        foreach ($rows as $index => $row) {
            try {
                $created[] = $this->register($row, $userId);
            } catch (DairyCheeseException $e) {
                $rejected[] = ['index' => $index, 'errors' => [$e->errorCode()]];
            } catch (ValidationException $e) {
                $rejected[] = ['index' => $index, 'errors' => array_values(array_merge(...array_values($e->errors())))];
            }
        }

        return ['created' => $created, 'rejected' => $rejected];
    }

    public function delete(MilkDelivery $delivery): void
    {
        if ($delivery->monthly_settlement_id !== null) {
            throw new DairyCheeseException('The delivery belongs to a settlement.', 'delivery_settled');
        }

        if (abs((float) $delivery->remaining_liters - (float) $delivery->liters) > 0.0005 && $delivery->status !== 'rejected') {
            throw new DairyCheeseException('Milk of the delivery has already been used in a vat.', 'delivery_already_used');
        }

        $delivery->delete();
    }

    public function release(MilkDelivery $delivery): MilkDelivery
    {
        if ($delivery->status !== 'quarantined') {
            throw new DairyCheeseException('Only milk in quarantine can be released.', 'delivery_not_quarantined');
        }

        $delivery->forceFill(['status' => 'accepted'])->save();

        return $delivery;
    }

    public function reject(MilkDelivery $delivery): MilkDelivery
    {
        if ($delivery->monthly_settlement_id !== null) {
            throw new DairyCheeseException('The delivery belongs to a settlement.', 'delivery_settled');
        }

        $this->tanks->discard($delivery);

        return $delivery->refresh();
    }
}
