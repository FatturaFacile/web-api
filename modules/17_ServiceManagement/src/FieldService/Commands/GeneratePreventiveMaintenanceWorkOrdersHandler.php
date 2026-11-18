<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Evaluates the preventive rule of every active maintenance contract against its equipment (GH-197).
 *
 * Rule shape (JSON on the contract):
 *  {"calendar": {"every": 6, "unit": "month"}, "meter": {"operating_hours": 500, "cycles": 10000, "lead_percent": 10}, "lead_days": 14}
 *
 * A maintenance cycle is identified by a stable key (calendar due date, or the meter target) stored on the work order
 * under a unique index, so running the engine again (daily job or manual trigger) never creates a second work order for
 * the same cycle. The cycle ends when a signed service report resets the equipment's "last service" counters.
 */
final class GeneratePreventiveMaintenanceWorkOrdersHandler
{
    private const DEFAULT_LEAD_DAYS = 14;

    private const DEFAULT_LEAD_PERCENT = 10;

    /**
     * @return array{evaluated_assets: int, created: list<string>, skipped_existing: int}
     */
    public function __invoke(GeneratePreventiveMaintenanceWorkOrdersCommand $command): array
    {
        $today = ($command->asOf ?? CarbonImmutable::now())->startOfDay();
        $created = [];
        $skipped = 0;
        $evaluated = 0;

        $contracts = ServiceContract::query()
            ->where('tenant_id', $command->tenantId)
            ->where('status', 'active')
            ->whereNotNull('preventive_frequency_rule')
            ->where(static fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today->toDateString()))
            ->whereDate('start_date', '<=', $today->toDateString())
            ->get();

        foreach ($contracts as $contract) {
            $rule = (array) $contract->preventive_frequency_rule;

            if ($rule === []) {
                continue;
            }

            $assets = SerializedEquipmentPassport::query()
                ->where('tenant_id', $command->tenantId)
                ->where('service_contract_id', $contract->id)
                ->get();

            foreach ($assets as $asset) {
                $evaluated++;
                $due = $this->evaluate($rule, $asset, $contract, $today);

                if ($due === []) {
                    continue;
                }

                $this->refreshNextServiceDate($asset, $rule, $contract);

                // Calendar, operating-hours and cycle triggers are independent cycles: each gets its own work order once.
                foreach ($due as $trigger) {
                    $workOrder = $this->createOnce($command->tenantId, $contract, $asset, $trigger['key'], $trigger['reason']);

                    if ($workOrder === null) {
                        $skipped++;
                    } else {
                        $created[] = (string) $workOrder->id;
                    }
                }
            }
        }

        return ['evaluated_assets' => $evaluated, 'created' => $created, 'skipped_existing' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<array{key: string, reason: string}>
     */
    private function evaluate(array $rule, SerializedEquipmentPassport $asset, ServiceContract $contract, CarbonImmutable $today): array
    {
        $due = [];

        $leadDays = (int) ($rule['lead_days'] ?? self::DEFAULT_LEAD_DAYS);

        $calendar = (array) ($rule['calendar'] ?? []);

        if ($calendar !== [] && (int) ($calendar['every'] ?? 0) > 0) {
            $next = $this->nextCalendarDate($calendar, $asset, $contract);

            if ($next !== null && $today->greaterThanOrEqualTo($next->subDays($leadDays))) {
                $due[] = ['key' => "cal:{$asset->id}:{$next->toDateString()}", 'reason' => "calendar due {$next->toDateString()}"];
            }
        }

        $meter = (array) ($rule['meter'] ?? []);
        $lead = (float) ($meter['lead_percent'] ?? self::DEFAULT_LEAD_PERCENT) / 100;

        $hoursThreshold = (float) ($meter['operating_hours'] ?? 0);

        if ($hoursThreshold > 0) {
            $last = (float) $asset->last_service_operating_hours;
            $target = $last + $hoursThreshold;

            if ((float) $asset->current_operating_hours >= $last + $hoursThreshold * (1 - $lead)) {
                $due[] = ['key' => "hrs:{$asset->id}:".rtrim(rtrim(number_format($target, 2, '.', ''), '0'), '.'), 'reason' => "operating hours reached {$asset->current_operating_hours} of target {$target}"];
            }
        }

        $cyclesThreshold = (int) ($meter['cycles'] ?? 0);

        if ($cyclesThreshold > 0) {
            $last = (int) $asset->last_service_cycle_count;
            $target = $last + $cyclesThreshold;

            if ((int) $asset->current_cycle_count >= $last + $cyclesThreshold * (1 - $lead)) {
                $due[] = ['key' => "cyc:{$asset->id}:{$target}", 'reason' => "cycle count reached {$asset->current_cycle_count} of target {$target}"];
            }
        }

        return $due;
    }

    /**
     * @param  array<string, mixed>  $calendar
     */
    private function nextCalendarDate(array $calendar, SerializedEquipmentPassport $asset, ServiceContract $contract): ?CarbonImmutable
    {
        $base = $asset->last_service_date ?? $contract->start_date;

        if ($base === null) {
            return null;
        }

        return self::calendarDueAfter($calendar, CarbonImmutable::parse($base));
    }

    /**
     * The next calendar due date after a service done on $base, for a rule {"every": n, "unit": day|week|month|year}.
     *
     * @param  array<string, mixed>  $calendar
     */
    public static function calendarDueAfter(array $calendar, CarbonImmutable $base): ?CarbonImmutable
    {
        $every = (int) ($calendar['every'] ?? 0);

        if ($every <= 0) {
            return null;
        }

        $base = $base->startOfDay();

        return match ((string) ($calendar['unit'] ?? 'month')) {
            'day' => $base->addDays($every),
            'week' => $base->addWeeks($every),
            'year' => $base->addYears($every),
            default => $base->addMonths($every),
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function refreshNextServiceDate(SerializedEquipmentPassport $asset, array $rule, ServiceContract $contract): void
    {
        $calendar = (array) ($rule['calendar'] ?? []);

        if ($calendar === [] || (int) ($calendar['every'] ?? 0) <= 0) {
            return;
        }

        $next = $this->nextCalendarDate($calendar, $asset, $contract);

        if ($next !== null && $asset->next_scheduled_service_date?->toDateString() !== $next->toDateString()) {
            $asset->update(['next_scheduled_service_date' => $next->toDateString()]);
        }
    }

    private function createOnce(string $tenantId, ServiceContract $contract, SerializedEquipmentPassport $asset, string $cycleKey, string $reason): ?WorkOrder
    {
        $exists = WorkOrder::query()->where('tenant_id', $tenantId)->where('preventive_cycle_key', $cycleKey)->exists();

        if ($exists) {
            return null;
        }

        try {
            return DB::transaction(static fn (): WorkOrder => WorkOrder::create([
                'tenant_id' => $tenantId,
                'company_id' => $contract->company_id,
                'work_order_number' => 'WO-'.date('Ymd').'-'.strtoupper(Str::random(6)),
                'title' => "Preventive maintenance {$asset->serial_number}",
                'description' => "Generated by contract {$contract->contract_number}: {$reason}",
                'work_type' => 'preventive',
                'priority' => 'medium',
                'status' => 'unscheduled',
                'customer_id' => $asset->current_owner_customer_id,
                'service_contract_id' => $contract->id,
                'equipment_passport_id' => $asset->id,
                'preventive_cycle_key' => $cycleKey,
                'currency' => $contract->currency,
                'location' => [
                    'address' => $asset->site_address,
                    'lat' => $asset->site_latitude,
                    'lng' => $asset->site_longitude,
                ],
            ]));
        } catch (QueryException) {
            // A concurrent run created the same cycle first: the unique index kept the engine idempotent.
            return null;
        }
    }
}
