<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Queries;

use Carbon\CarbonImmutable;
use Modules\ServiceManagement\FieldService\Commands\DispatchTechnicianWorkOrderHandler;
use Modules\ServiceManagement\FieldService\VanWarehouseResolver;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Read model of the dispatcher board: the unassigned queue, one lane per technician with the scheduled blocks of the
 * window, the technician skills, the van stock readiness and the customer site coordinates for the map.
 */
final readonly class GetDispatcherGanttAndMapBoardQuery
{
    public function __construct(private VanWarehouseResolver $vans) {}

    /**
     * @return array{window: array{from: string, to: string}, unassigned: list<array<string, mixed>>, lanes: list<array<string, mixed>>}
     */
    public function __invoke(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $unassigned = WorkOrder::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['draft', 'unscheduled'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        $scheduled = WorkOrder::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('assigned_technician_id')
            ->whereIn('status', DispatchTechnicianWorkOrderHandler::BusyStatuses)
            ->where('scheduled_start', '<', $to)
            ->where('scheduled_end', '>', $from)
            ->orderBy('scheduled_start')
            ->get()
            ->groupBy('assigned_technician_id');

        $lanes = [];

        foreach (ServiceTechnician::query()->where('tenant_id', $tenantId)->orderBy('first_name')->orderBy('last_name')->get() as $technician) {
            $van = $this->vans->forTechnician($technician);
            $stock = $van === null ? [] : $this->vans->availableByProduct($van);

            $lanes[] = [
                'technician_id' => (string) $technician->id,
                'name' => (string) $technician->name,
                'is_available' => (bool) $technician->is_available,
                'skills' => DispatchTechnicianWorkOrderHandler::normalizeSkills((array) $technician->skills),
                'van' => [
                    'warehouse_id' => $van === null ? null : (string) $van->id,
                    'ready' => $van !== null,
                    'distinct_products_in_stock' => count(array_filter($stock, static fn (float $q): bool => $q > 0)),
                ],
                'blocks' => ($scheduled[$technician->id] ?? collect())->map(fn (WorkOrder $wo): array => $this->block($wo))->values()->all(),
            ];
        }

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'unassigned' => $unassigned->map(fn (WorkOrder $wo): array => $this->block($wo) + [
                'required_skills' => DispatchTechnicianWorkOrderHandler::normalizeSkills((array) $wo->required_skills),
                'required_parts' => array_values((array) $wo->required_parts),
            ])->values()->all(),
            'lanes' => $lanes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function block(WorkOrder $wo): array
    {
        $location = (array) $wo->location;

        return [
            'work_order_id' => (string) $wo->id,
            'work_order_number' => (string) $wo->work_order_number,
            'title' => (string) $wo->title,
            'work_type' => (string) $wo->work_type,
            'priority' => (string) $wo->priority,
            'status' => (string) $wo->status,
            'scheduled_start' => $wo->scheduled_start?->toIso8601String(),
            'scheduled_end' => $wo->scheduled_end?->toIso8601String(),
            'site' => [
                'address' => $location['address'] ?? null,
                'latitude' => isset($location['lat']) ? (float) $location['lat'] : null,
                'longitude' => isset($location['lng']) ? (float) $location['lng'] : null,
            ],
        ];
    }
}
