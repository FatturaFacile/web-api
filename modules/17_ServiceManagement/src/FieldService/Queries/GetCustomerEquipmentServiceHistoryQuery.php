<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Queries;

use Modules\ServiceManagement\Models\DigitalServiceReport;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Chronological service history of one piece of customer equipment: the signed reports, the parts replaced in each and
 * the meter progression.
 */
final class GetCustomerEquipmentServiceHistoryQuery
{
    /**
     * @return array{equipment: array<string, mixed>, history: list<array<string, mixed>>}
     */
    public function __invoke(string $tenantId, string $passportId): array
    {
        $passport = SerializedEquipmentPassport::query()->where('tenant_id', $tenantId)->findOrFail($passportId);
        $workOrders = WorkOrder::query()->where('tenant_id', $tenantId)->where('equipment_passport_id', $passport->id)->get()->keyBy('id');

        $reports = DigitalServiceReport::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('work_order_id', $workOrders->keys())
            ->orderBy('signed_at')
            ->get();

        $parts = PartsConsumption::query()->whereIn('work_order_id', $workOrders->keys())->get()->groupBy('work_order_id');

        return [
            'equipment' => [
                'id' => (string) $passport->id,
                'serial_number' => (string) $passport->serial_number,
                'current_operating_hours' => (string) $passport->current_operating_hours,
                'current_cycle_count' => (int) $passport->current_cycle_count,
                'last_service_date' => $passport->last_service_date?->toDateString(),
                'next_scheduled_service_date' => $passport->next_scheduled_service_date?->toDateString(),
            ],
            'history' => $reports->map(static fn (DigitalServiceReport $r): array => [
                'report_id' => (string) $r->id,
                'report_number' => (string) $r->report_number,
                'work_order_id' => (string) $r->work_order_id,
                'work_type' => (string) ($workOrders[$r->work_order_id]->work_type ?? ''),
                'signed_at' => $r->signed_at->toIso8601String(),
                'summary' => (string) $r->work_performed_summary,
                'labor_hours' => (string) $r->labor_hours,
                'meter_reading_hours' => $r->meter_reading_hours,
                'meter_reading_cycles' => $r->meter_reading_cycles,
                'parts' => ($parts[$r->work_order_id] ?? collect())->map(static fn (PartsConsumption $p): array => [
                    'product_id' => (string) $p->product_id,
                    'quantity' => (string) $p->quantity,
                    'covered' => (bool) $p->is_covered_by_warranty_or_contract,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
