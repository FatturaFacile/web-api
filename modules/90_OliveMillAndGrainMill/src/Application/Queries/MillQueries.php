<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\OliveMillAndGrainMill\Domain\Enums\SianStatus;
use Modules\OliveMillAndGrainMill\Domain\Services\SianComplianceService;
use Modules\OliveMillAndGrainMill\Models\ByproductMovement;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\MillingSettlement;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;
use Modules\OliveMillAndGrainMill\Models\SianMovement;
use Modules\OliveMillAndGrainMill\Models\SianTank;

/**
 * Read models of the lists of the module.
 *
 * @phpstan-type Filters array<string, mixed>
 */
final class MillQueries
{
    private const string FARMER_COLUMNS = 'farmer:id,company_name,first_name,last_name,phone';

    public function __construct(private readonly SianComplianceService $compliance) {}

    /**
     * @param  Filters  $filters  status, commodity, processing_mode, campaign_year, farmer_contact_id, search, date_from, date_to
     * @return LengthAwarePaginator<int, OliveDelivery>
     */
    public function deliveries(array $filters, int $perPage): LengthAwarePaginator
    {
        return OliveDelivery::query()
            ->with([self::FARMER_COLUMNS, 'cycle:id,delivery_id,status,yield_percent,output_kg'])
            ->when($filters['status'] ?? null, static fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['commodity'] ?? null, static fn (Builder $q, string $v) => $q->where('commodity', $v))
            ->when($filters['processing_mode'] ?? null, static fn (Builder $q, string $v) => $q->where('processing_mode', $v))
            ->when($filters['campaign_year'] ?? null, static fn (Builder $q, mixed $v) => $q->where('campaign_year', (int) $v))
            ->when($filters['farmer_contact_id'] ?? null, static fn (Builder $q, string $v) => $q->where('farmer_contact_id', $v))
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('delivered_at', '>=', $v))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('delivered_at', '<=', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $this->like($q, ['lot_code', 'cultivar', 'weighbridge_ticket'], $v))
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function delivery(string $id): OliveDelivery
    {
        return OliveDelivery::query()->with([self::FARMER_COLUMNS, 'cycle', 'settlement'])->findOrFail($id);
    }

    /**
     * Bookings of the period grouped by milling line (the lines without a name go under "").
     *
     * @return Collection<int, OliveDelivery>
     */
    public function schedule(Carbon $from, Carbon $to, ?string $line): Collection
    {
        return OliveDelivery::query()
            ->with([self::FARMER_COLUMNS])
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$from, $to])
            ->where('status', '!=', 'CANCELLED')
            ->when($line, static fn (Builder $q, string $v) => $q->where('hopper_line', $v))
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * @param  Filters  $filters  status, delivery_id, line_code, date_from, date_to
     * @return LengthAwarePaginator<int, MillingCycle>
     */
    public function cycles(array $filters, int $perPage): LengthAwarePaginator
    {
        return MillingCycle::query()
            ->with(['delivery:id,lot_code,commodity,processing_mode,certification,farmer_contact_id,campaign_year', 'delivery.farmer:id,company_name,first_name,last_name'])
            ->when($filters['status'] ?? null, static fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['delivery_id'] ?? null, static fn (Builder $q, string $v) => $q->where('delivery_id', $v))
            ->when($filters['line_code'] ?? null, static fn (Builder $q, string $v) => $q->where('line_code', $v))
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('started_at', '>=', $v))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('started_at', '<=', $v))
            ->orderByDesc('started_at')
            ->paginate($perPage);
    }

    public function cycle(string $id): MillingCycle
    {
        return MillingCycle::query()->with(['delivery.farmer', 'targetTank:id,code', 'settlement'])->findOrFail($id);
    }

    /**
     * @param  Filters  $filters  delivery_id, settlement_mode, date_from, date_to
     * @return LengthAwarePaginator<int, MillingSettlement>
     */
    public function settlements(array $filters, int $perPage): LengthAwarePaginator
    {
        return MillingSettlement::query()
            ->with(['delivery:id,lot_code,farmer_contact_id', 'delivery.farmer:id,company_name,first_name,last_name'])
            ->when($filters['delivery_id'] ?? null, static fn (Builder $q, string $v) => $q->where('delivery_id', $v))
            ->when($filters['settlement_mode'] ?? null, static fn (Builder $q, string $v) => $q->where('settlement_mode', $v))
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('settled_at', '>=', $v))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('settled_at', '<=', $v))
            ->orderByDesc('settled_at')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, SianTank>
     */
    public function tanks(): Collection
    {
        return SianTank::query()->orderBy('grid_row')->orderBy('grid_col')->orderBy('code')->get();
    }

    public function tank(string $id): SianTank
    {
        return SianTank::query()->findOrFail($id);
    }

    /**
     * @param  Filters  $filters  status, causal, tank_id, pending, overdue, date_from, date_to
     * @return LengthAwarePaginator<int, SianMovement>
     */
    public function movements(array $filters, int $perPage): LengthAwarePaginator
    {
        $pending = array_map(static fn (SianStatus $s): string => $s->value, array_filter(SianStatus::cases(), static fn (SianStatus $s): bool => $s->isPending()));

        return SianMovement::query()
            ->with(['fromTank:id,code', 'toTank:id,code'])
            ->when($filters['status'] ?? null, static fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['causal'] ?? null, static fn (Builder $q, string $v) => $q->where('causal', $v))
            ->when($filters['tank_id'] ?? null, static fn (Builder $q, string $v) => $q->where(static fn (Builder $w) => $w->where('from_tank_id', $v)->orWhere('to_tank_id', $v)))
            ->when(filter_var($filters['pending'] ?? false, FILTER_VALIDATE_BOOLEAN), static fn (Builder $q) => $q->whereIn('status', $pending))
            ->when(filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN), static fn (Builder $q) => $q->whereIn('status', $pending)->whereDate('transmission_deadline', '<', now()->toDateString()))
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('operation_date', '>=', $v))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('operation_date', '<=', $v))
            ->orderByDesc('progressive')
            ->paginate($perPage);
    }

    /**
     * The untransmitted operations that are overdue or about to be, most urgent first.
     *
     * @return array{overdue: int, due_soon: int, items: list<array<string, mixed>>}
     */
    public function complianceAlerts(): array
    {
        $pending = array_map(static fn (SianStatus $s): string => $s->value, array_filter(SianStatus::cases(), static fn (SianStatus $s): bool => $s->isPending()));
        $items = [];
        $counts = ['overdue' => 0, 'due_soon' => 0];

        $rows = SianMovement::query()->whereIn('status', $pending)->orderBy('transmission_deadline')->orderBy('progressive')->get();

        foreach ($rows as $movement) {
            $level = $this->compliance->alertLevel($movement->transmission_deadline);

            if ($level === 'ok') {
                continue;
            }

            $counts[$level]++;
            $items[] = [
                'id' => (string) $movement->id,
                'progressive' => $movement->progressive,
                'causal' => $movement->causal->value,
                'operation_date' => $movement->operation_date->toDateString(),
                'transmission_deadline' => $movement->transmission_deadline->toDateString(),
                'days_left' => $this->compliance->daysLeft($movement->transmission_deadline),
                'alert_level' => $level,
                'quantity_kg' => $movement->quantity_kg,
                'status' => $movement->status->value,
            ];
        }

        return ['overdue' => $counts['overdue'], 'due_soon' => $counts['due_soon'], 'items' => $items];
    }

    /**
     * @param  Filters  $filters  byproduct_type, cadastral_parcel, date_from, date_to
     * @return LengthAwarePaginator<int, ByproductMovement>
     */
    public function byproducts(array $filters, int $perPage): LengthAwarePaginator
    {
        return ByproductMovement::query()
            ->when($filters['byproduct_type'] ?? null, static fn (Builder $q, string $v) => $q->where('byproduct_type', $v))
            ->when($filters['cadastral_parcel'] ?? null, static fn (Builder $q, string $v) => $q->where('cadastral_parcel', $v))
            ->when($filters['date_from'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('movement_date', '>=', $v))
            ->when($filters['date_to'] ?? null, static fn (Builder $q, string $v) => $q->whereDate('movement_date', '<=', $v))
            ->orderByDesc('movement_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  Builder<*>  $query
     * @param  list<string>  $columns
     * @return Builder<*>
     */
    private function like(Builder $query, array $columns, string $needle): Builder
    {
        $pattern = '%'.strtolower(addcslashes($needle, '%_\\')).'%';

        return $query->where(static function (Builder $q) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $q->orWhereRaw('LOWER('.$column.') LIKE ?', [$pattern]);
            }
        });
    }
}
