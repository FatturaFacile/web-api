<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Queries;

use App\Models\Contact;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;
use Modules\ServiceManagement\FieldService\VanWarehouseResolver;
use Modules\ServiceManagement\Models\DigitalServiceReport;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Delta-sync bundle for the technician's offline mobile client: the open work orders assigned to the technician, their
 * customers and equipment (with recent service history), the default checklists and the full van stock catalog.
 *
 * With `since` only the work orders, customers and equipment changed after that instant are returned;
 * `open_work_order_ids` always lists every open assignment so the client can drop the ones that left its queue. The
 * van catalog is small and returned in full each time. Clients store `server_time` and pass it back as `since`.
 */
final readonly class GetTechnicianOfflineWorkQueueQuery
{
    private const OpenStatuses = ['dispatched', 'en_route', 'on_site', 'in_progress'];

    /** @var array<string, list<array{item: string}>> */
    private const DefaultChecklists = [
        'preventive' => [['item' => 'visual_inspection'], ['item' => 'lubrication'], ['item' => 'safety_devices'], ['item' => 'functional_test']],
        'corrective' => [['item' => 'fault_diagnosis'], ['item' => 'repair_verified'], ['item' => 'functional_test']],
        'installation' => [['item' => 'installation_complete'], ['item' => 'commissioning'], ['item' => 'customer_briefing']],
        'warranty' => [['item' => 'fault_diagnosis'], ['item' => 'repair_verified'], ['item' => 'functional_test']],
    ];

    public function __construct(private VanWarehouseResolver $vans, private StockService $stock) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $tenantId, string $technicianId, ?CarbonImmutable $since = null): array
    {
        $serverTime = CarbonImmutable::now();
        $technician = ServiceTechnician::query()->where('tenant_id', $tenantId)->findOrFail($technicianId);

        $open = WorkOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('assigned_technician_id', $technician->id)
            ->whereIn('status', self::OpenStatuses);

        $openIds = (clone $open)->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $changed = $since === null ? (clone $open) : (clone $open)->where('updated_at', '>', $since);
        $workOrders = $changed->orderBy('scheduled_start')->get();

        $passports = SerializedEquipmentPassport::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $workOrders->pluck('equipment_passport_id')->filter()->unique())
            ->get();
        $customers = Contact::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $workOrders->pluck('customer_id')->filter()->unique())
            ->get();

        $contracts = ServiceContract::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $workOrders->pluck('service_contract_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $van = $this->vans->forTechnician($technician);

        return [
            'server_time' => $serverTime->toIso8601String(),
            'technician' => [
                'id' => (string) $technician->id,
                'name' => (string) $technician->name,
                'skills' => (array) $technician->skills,
                'van_warehouse_id' => $van === null ? null : (string) $van->id,
            ],
            'open_work_order_ids' => $openIds,
            'work_orders' => $workOrders->map(fn (WorkOrder $wo): array => [
                'id' => (string) $wo->id,
                'work_order_number' => (string) $wo->work_order_number,
                'work_type' => (string) $wo->work_type,
                'priority' => (string) $wo->priority,
                'status' => (string) $wo->status,
                'title' => (string) $wo->title,
                'problem_description' => $wo->description,
                'customer_id' => $wo->customer_id === null ? null : (string) $wo->customer_id,
                'equipment_passport_id' => $wo->equipment_passport_id === null ? null : (string) $wo->equipment_passport_id,
                'service_contract_id' => $wo->service_contract_id === null ? null : (string) $wo->service_contract_id,
                'service_contract' => $this->contractRules($contracts[$wo->service_contract_id] ?? null),
                'scheduled_start' => $wo->scheduled_start?->toIso8601String(),
                'scheduled_end' => $wo->scheduled_end?->toIso8601String(),
                'site' => (array) $wo->location,
                'required_skills' => array_values((array) $wo->required_skills),
                'checklist_template' => self::DefaultChecklists[(string) $wo->work_type] ?? self::DefaultChecklists['corrective'],
                'updated_at' => $wo->updated_at?->toIso8601String(),
            ])->values()->all(),
            'customers' => $customers->map(static fn (Contact $c): array => [
                'id' => (string) $c->id,
                'name' => (string) ($c->company_name ?: trim(($c->first_name ?? '').' '.($c->last_name ?? ''))),
                'email' => $c->email,
                'phone' => $c->phone ?? null,
            ])->values()->all(),
            'equipment' => $passports->map(fn (SerializedEquipmentPassport $p): array => [
                'id' => (string) $p->id,
                'serial_number' => (string) $p->serial_number,
                'product_id' => $p->product_id === null ? null : (string) $p->product_id,
                'site_address' => $p->site_address,
                'site_latitude' => $p->site_latitude,
                'site_longitude' => $p->site_longitude,
                'current_operating_hours' => (string) $p->current_operating_hours,
                'current_cycle_count' => (int) $p->current_cycle_count,
                'last_service_date' => $p->last_service_date?->toDateString(),
                'history' => $this->recentHistory((string) $p->id, $tenantId),
            ])->values()->all(),
            'van_stock' => $van === null ? [] : $this->vanCatalog($van),
        ];
    }

    /**
     * Coverage rules the offline client needs to flag covered vs chargeable parts and fees without connectivity.
     *
     * @return array<string, mixed>|null
     */
    private function contractRules(?ServiceContract $contract): ?array
    {
        if ($contract === null) {
            return null;
        }

        return [
            'id' => (string) $contract->id,
            'contract_number' => $contract->contract_number,
            'coverage_type' => (string) $contract->coverage_type,
            'status' => (string) $contract->status,
            'start_date' => $contract->start_date->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'callout_fee_amount' => (string) $contract->callout_fee_amount,
            'hourly_labor_rate' => $contract->hourly_labor_rate === null ? null : (string) $contract->hourly_labor_rate,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentHistory(string $passportId, string $tenantId): array
    {
        $workOrderIds = WorkOrder::query()->where('tenant_id', $tenantId)->where('equipment_passport_id', $passportId)->pluck('id');

        return DigitalServiceReport::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('work_order_id', $workOrderIds)
            ->orderByDesc('signed_at')
            ->limit(5)
            ->get()
            ->map(static fn (DigitalServiceReport $r): array => [
                'report_number' => (string) $r->report_number,
                'signed_at' => $r->signed_at->toIso8601String(),
                'summary' => (string) $r->work_performed_summary,
                'meter_reading_hours' => $r->meter_reading_hours,
                'meter_reading_cycles' => $r->meter_reading_cycles,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vanCatalog(Warehouse $van): array
    {
        $levels = $this->stock->levels((string) $van->id);
        $products = Product::query()->whereIn('id', $levels->pluck('product_id'))->get()->keyBy('id');

        return $levels->map(static fn (array $row): array => [
            'product_id' => $row['product_id'],
            'sku' => $row['sku'],
            'name' => $row['name'],
            'available' => $row['available'],
            'unit_price' => (string) ($products[$row['product_id']]->sale_price ?? '0.00'),
        ])->values()->all();
    }
}
