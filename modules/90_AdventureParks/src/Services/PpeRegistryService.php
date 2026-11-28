<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AdventureParks\Domain\Enums\AlertType;
use Modules\AdventureParks\Domain\Enums\InspectionOutcome;
use Modules\AdventureParks\Domain\Enums\InspectionType;
use Modules\AdventureParks\Domain\Enums\PpeStatus;
use Modules\AdventureParks\Exceptions\AdventureParksException;
use Modules\AdventureParks\Models\DailyCourseCheck;
use Modules\AdventureParks\Models\PpeAsset;
use Modules\AdventureParks\Models\PpeInspectionLog;
use Modules\AdventureParks\Models\TicketPpeAssignment;

/**
 * The PPE register (UNI EN 365 / EN 15567): items in bulk, inspections with their effect on the status, the expiry alerts and the
 * signed daily opening check of the courses.
 */
final class PpeRegistryService
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array{category?: string|null, size?: string|null, status?: string|null, search?: string|null}  $filters
     * @return Collection<int, PpeAsset>
     */
    public function list(array $filters): Collection
    {
        return PpeAsset::query()
            ->when($filters['category'] ?? null, fn (Builder $query, string $value) => $query->where('category', $value))
            ->when($filters['size'] ?? null, fn (Builder $query, string $value) => $query->where('size', $value))
            ->when($filters['status'] ?? null, fn (Builder $query, string $value) => $query->where('status', $value))
            ->when($filters['search'] ?? null, function (Builder $query, string $value): void {
                $like = '%'.mb_strtolower($value).'%';
                $query->where(fn (Builder $inner) => $inner
                    ->whereRaw('LOWER(serial_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(brand, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(model, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(batch_number, \'\')) LIKE ?', [$like]));
            })
            ->orderBy('serial_code')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): PpeAsset
    {
        $data = $this->withoutNullDefaults($data);
        $asset = new PpeAsset($data + ['tenant_id' => (string) $this->tenants->getTenantId(), 'status' => PpeStatus::Available->value, 'created_by' => $userId]);
        $asset->syncStatus(CarbonImmutable::now());
        $asset->save();

        return $asset->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PpeAsset $asset, array $data, ?string $userId): PpeAsset
    {
        $asset->fill($this->withoutNullDefaults($data));
        $asset->updated_by = $userId;
        $asset->syncStatus(CarbonImmutable::now());
        $asset->save();

        return $asset->refresh();
    }

    public function delete(PpeAsset $asset): void
    {
        $out = TicketPpeAssignment::query()->where('ppe_asset_id', $asset->id)->whereNull('returned_at')->exists();

        if ($out || $asset->statusEnum() === PpeStatus::InUse) {
            throw AdventureParksException::refuse('ppe_in_use', 'The PPE is out with a visitor and cannot be deleted.', ['serial_code' => $asset->serial_code]);
        }

        $asset->delete();
    }

    /**
     * Registers `count` items of the same batch with the serial codes PREFIX-000001, PREFIX-000002, ... continuing after the
     * highest serial already registered with that prefix.
     *
     * @param  array<string, mixed>  $data
     * @return list<PpeAsset>
     */
    public function createBatch(array $data, ?string $userId): array
    {
        $prefix = (string) $data['prefix'];
        $count = (int) $data['count'];
        unset($data['prefix'], $data['count']);

        return DB::transaction(function () use ($prefix, $count, $data, $userId): array {
            $last = 0;

            $existing = PpeAsset::query()->withTrashed()->where('serial_code', 'like', $prefix.'-%')->pluck('serial_code');

            foreach ($existing as $serial) {
                $suffix = substr((string) $serial, strlen($prefix) + 1);

                if (ctype_digit($suffix)) {
                    $last = max($last, (int) $suffix);
                }
            }

            $created = [];

            for ($i = 1; $i <= $count; $i++) {
                $created[] = $this->create($data + ['serial_code' => sprintf('%s-%06d', $prefix, $last + $i)], $userId);
            }

            return $created;
        });
    }

    /**
     * Records an inspection and applies it to the item: a FAIL sends it to maintenance, a passed annual EN 365 inspection unlocks it
     * and moves the next due date a year ahead.
     *
     * @param  array<string, mixed>  $data
     */
    public function inspect(PpeAsset $asset, array $data, ?string $userId): PpeInspectionLog
    {
        $type = InspectionType::from((string) $data['inspection_type']);
        $outcome = InspectionOutcome::from((string) $data['outcome']);
        $on = CarbonImmutable::parse((string) $data['inspected_on']);
        $nextDue = isset($data['next_due_date']) ? CarbonImmutable::parse((string) $data['next_due_date']) : null;

        return DB::transaction(function () use ($asset, $data, $type, $outcome, $on, $nextDue, $userId): PpeInspectionLog {
            $locked = PpeAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $locked->applyInspection($type, $outcome, $on, $nextDue);
            $locked->save();

            $isAnnualPass = $type === InspectionType::AnnualEn365 && $outcome === InspectionOutcome::Pass;

            $log = new PpeInspectionLog([
                'tenant_id' => $locked->tenant_id,
                'ppe_asset_id' => $locked->id,
                'inspection_type' => $type->value,
                'outcome' => $outcome->value,
                'inspected_on' => $on->toDateString(),
                'inspector_name' => $data['inspector_name'] ?? null,
                'inspector_license_number' => $data['inspector_license_number'] ?? null,
                'signature' => $data['signature'] ?? null,
                'notes' => $data['notes'] ?? null,
                'checklist' => $data['checklist'] ?? null,
                'next_due_date' => $isAnnualPass ? $locked->next_inspection_due_date?->toDateString() : ($nextDue?->toDateString()),
                'created_by' => $userId,
            ]);
            $log->save();

            return $log;
        });
    }

    /**
     * Expiry alerts of the register: lifespan and EN 365 inspection, overdue or coming due within the warning window.
     *
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>, daily_check_done_today: bool}
     */
    public function alerts(CarbonImmutable $today): array
    {
        $window = (int) config('adventure_parks.alert_days', 30);
        $items = [];
        $counts = ['lifespan_expiring' => 0, 'lifespan_expired' => 0, 'inspection_due_soon' => 0, 'inspection_overdue' => 0];

        $assets = PpeAsset::query()->where('status', '!=', PpeStatus::Decommissioned->value)->orderBy('serial_code')->get();

        foreach ($assets as $asset) {
            $lifespan = $asset->manufacturer_max_lifespan_date->toImmutable()->startOfDay();
            $days = (int) $today->diffInDays($lifespan, false);

            if ($days < 0) {
                $counts['lifespan_expired']++;
                $items[] = $this->item(AlertType::LifespanExpired, $asset, $lifespan->toDateString(), $days);
            } elseif ($days <= $window) {
                $counts['lifespan_expiring']++;
                $items[] = $this->item(AlertType::LifespanExpiring, $asset, $lifespan->toDateString(), $days);
            }

            if ($asset->next_inspection_due_date === null) {
                continue;
            }

            $due = $asset->next_inspection_due_date->toImmutable()->startOfDay();
            $days = (int) $today->diffInDays($due, false);

            if ($days < 0) {
                $counts['inspection_overdue']++;
                $items[] = $this->item(AlertType::InspectionOverdue, $asset, $due->toDateString(), $days);
            } elseif ($days <= $window) {
                $counts['inspection_due_soon']++;
                $items[] = $this->item(AlertType::InspectionDueSoon, $asset, $due->toDateString(), $days);
            }
        }

        return ['items' => $items, 'counts' => $counts, 'daily_check_done_today' => $this->dailyCheckFor($today) !== null];
    }

    public function dailyCheckFor(CarbonImmutable $date): ?DailyCourseCheck
    {
        return DailyCourseCheck::query()->whereDate('check_date', $date->toDateString())->latest('created_at')->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordDailyCheck(array $data, ?string $userId): DailyCourseCheck
    {
        /** @var array<int, array{key: string, ok: bool, notes?: string|null}> $items */
        $items = array_map(fn (array $item): array => [
            'key' => (string) $item['key'],
            'ok' => (bool) $item['ok'],
            'notes' => $item['notes'] ?? null,
        ], array_values((array) $data['items']));

        $check = new DailyCourseCheck([
            'tenant_id' => (string) $this->tenants->getTenantId(),
            'check_date' => $data['check_date'],
            'checked_by_name' => $data['checked_by_name'],
            'items' => $items,
            'outcome' => DailyCourseCheck::outcomeOf($items),
            'signature' => $data['signature'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
        $check->save();

        return $check;
    }

    /**
     * Columns that have a default in the database must not be written as null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutNullDefaults(array $data): array
    {
        foreach (['size', 'status', 'rental_cycle_count', 'cumulative_usage_hours'] as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                unset($data[$column]);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(AlertType $type, PpeAsset $asset, string $dueDate, int $days): array
    {
        return [
            'type' => $type->value,
            'ppe_id' => $asset->id,
            'serial_code' => $asset->serial_code,
            'category' => $asset->category,
            'due_date' => $dueDate,
            'days' => $days,
        ];
    }
}
