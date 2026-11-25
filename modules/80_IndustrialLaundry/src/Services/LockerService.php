<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;
use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Models\Garment;

/**
 * Garment -> employee -> locker ("armadietto", "casella"): who wears a nominal garment and where it is handed over.
 */
final class LockerService
{
    public function __construct(private readonly GarmentLifecycleService $lifecycle) {}

    public function assign(Garment $garment, string $employeeName, string $lockerCode, ?string $customerId, ?string $userId): Garment
    {
        if ($garment->current_lifecycle_state === LifecycleState::CondemnedRag) {
            throw new IndustrialLaundryException('garment_condemned');
        }

        if (! $garment->article_type->isNominal()) {
            throw new IndustrialLaundryException('garment_not_nominal');
        }

        $employeeName = trim($employeeName);
        $lockerCode = trim($lockerCode);

        if ($employeeName === '' || $lockerCode === '') {
            throw new IndustrialLaundryException('assignment_incomplete');
        }

        $customerId ??= $garment->assigned_customer_id;

        if ($customerId === null) {
            throw new IndustrialLaundryException('assignment_customer_required');
        }

        $this->assertLockerFree($garment, $employeeName, $lockerCode, $customerId);

        return DB::transaction(function () use ($garment, $employeeName, $lockerCode, $customerId, $userId): Garment {
            $garment->forceFill(['assigned_customer_id' => $customerId, 'assigned_wearer_employee_name' => $employeeName, 'assigned_locker_code' => $lockerCode, 'updated_by' => $userId])->save();
            $this->lifecycle->record($garment, GarmentEventType::Assigned, Carbon::now(), null, null, ['note' => $employeeName.' / '.$lockerCode, 'userId' => $userId]);

            return $garment;
        });
    }

    public function release(Garment $garment, ?string $userId): Garment
    {
        return DB::transaction(function () use ($garment, $userId): Garment {
            $note = trim((string) $garment->assigned_wearer_employee_name.' / '.(string) $garment->assigned_locker_code, ' /');
            $garment->forceFill(['assigned_wearer_employee_name' => null, 'assigned_locker_code' => null, 'updated_by' => $userId])->save();
            $this->lifecycle->record($garment, GarmentEventType::Released, Carbon::now(), null, null, ['note' => $note, 'userId' => $userId]);

            return $garment;
        });
    }

    /**
     * The employees of a customer with their locker and garments.
     *
     * @return list<array{employee: string, locker_code: string|null, customer_id: string, garments: list<array<string, mixed>>}>
     */
    public function assignments(?string $customerId, ?string $search): array
    {
        $rows = Garment::query()
            ->whereNotNull('assigned_wearer_employee_name')
            ->where('current_lifecycle_state', '!=', LifecycleState::CondemnedRag->value)
            ->when($customerId !== null, fn (Builder $query) => $query->where('assigned_customer_id', $customerId))
            ->when($search !== null && $search !== '', function (Builder $query) use ($search): void {
                $term = '%'.mb_strtolower((string) $search).'%';
                $query->where(fn (Builder $inner) => $inner->whereRaw('LOWER(assigned_wearer_employee_name) LIKE ?', [$term])->orWhereRaw('LOWER(assigned_locker_code) LIKE ?', [$term]));
            })
            ->orderBy('assigned_wearer_employee_name')
            ->orderBy('epc_uid_rfid_hex')
            ->get();

        $grouped = [];

        foreach ($rows as $garment) {
            $key = (string) $garment->assigned_customer_id.'|'.(string) $garment->assigned_wearer_employee_name.'|'.(string) $garment->assigned_locker_code;
            $grouped[$key] ??= ['employee' => (string) $garment->assigned_wearer_employee_name, 'locker_code' => $garment->assigned_locker_code, 'customer_id' => (string) $garment->assigned_customer_id, 'garments' => []];
            $grouped[$key]['garments'][] = [
                'id' => (string) $garment->id,
                'epc_uid_rfid_hex' => $garment->epc_uid_rfid_hex,
                'article_type' => $garment->article_type->value,
                'current_lifecycle_state' => $garment->current_lifecycle_state->value,
                'wash_cycles_count' => $garment->wash_cycles_count,
                'max_certified_wash_cycles_limit' => $garment->max_certified_wash_cycles_limit,
                'is_reissue_blocked' => $garment->is_reissue_blocked,
            ];
        }

        return array_values($grouped);
    }

    /**
     * A locker belongs to one employee of a customer.
     */
    private function assertLockerFree(Garment $garment, string $employeeName, string $lockerCode, string $customerId): void
    {
        $taken = Garment::query()
            ->where('assigned_customer_id', $customerId)
            ->where('assigned_locker_code', $lockerCode)
            ->where('assigned_wearer_employee_name', '!=', $employeeName)
            ->whereKeyNot($garment->getKey())
            ->where('current_lifecycle_state', '!=', LifecycleState::CondemnedRag->value)
            ->exists();

        if ($taken) {
            throw new IndustrialLaundryException('locker_taken', 422, ['locker' => $lockerCode]);
        }
    }
}
