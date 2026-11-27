<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Handlers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\GreenCare\Application\Commands\LogCrewSiteInterventionCommand;
use Modules\GreenCare\Application\Services\DocumentNumbering;
use Modules\GreenCare\Application\Services\PassageTracker;
use Modules\GreenCare\Application\Services\StockIssuer;
use Modules\GreenCare\Domain\Compliance\ComplianceStrategyResolver;
use Modules\GreenCare\Domain\Enums\RouteStatus;
use Modules\GreenCare\Domain\Exceptions\GreenCareException;
use Modules\GreenCare\Models\CrewRouteSchedule;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\GreenWasteSlip;

/**
 * Closes a stop with one tap of the crew: check-in and check-out with GPS, hours of the members and of the machines, plants and materials
 * (taken from the warehouse), the green waste weight (a disposal slip) and the passage on the contract. The passage beyond the included
 * passages (the 17th of 16) is flagged as extra and becomes ready to invoice at the extra passage price.
 */
final class LogCrewSiteInterventionHandler
{
    public function __construct(
        private readonly PassageTracker $passages,
        private readonly StockIssuer $stock,
        private readonly DocumentNumbering $numbering,
        private readonly ComplianceStrategyResolver $strategies,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @throws GreenCareException stop_already_completed, route_locked, warehouse_required
     */
    public function handle(LogCrewSiteInterventionCommand $command): CrewRouteStop
    {
        return DB::transaction(function () use ($command): CrewRouteStop {
            /** @var CrewRouteStop $stop */
            $stop = CrewRouteStop::query()->whereKey($command->stopId)->lockForUpdate()->firstOrFail();

            if ($stop->isCompleted()) {
                throw GreenCareException::because('stop_already_completed');
            }

            /** @var CrewRouteSchedule $schedule */
            $schedule = CrewRouteSchedule::query()->whereKey($stop->route_schedule_id)->lockForUpdate()->firstOrFail();

            if ($schedule->status === RouteStatus::Postponed) {
                throw GreenCareException::because('route_locked');
            }

            $checkout = $command->checkoutAt ?? now();
            $checkin = $command->checkinAt ?? $checkout;

            $stop->actual_checkin_at = $checkin;
            $stop->actual_checkout_at = $checkout;
            $stop->checkin_gps = $command->checkinGps;
            $stop->checkout_gps = $command->checkoutGps;
            $stop->crew_hours_logged = $this->crewHours($command->crewHours);
            $stop->machinery_hours_logged = array_map(static fn (array $row): array => ['machine' => (string) $row['machine'], 'hours' => round((float) $row['hours'], 2)], $command->machineryHours);
            $stop->materials_and_plants_used = $this->issueMaterials($command->materials, $stop);

            if ($command->countsTowardContractPassage !== null) {
                $stop->counts_toward_contract_passage = $command->countsTowardContractPassage;
            }

            $this->passages->register($stop);
            $stop->save();

            if ($command->greenWasteWeightKg !== null && $command->greenWasteWeightKg > 0) {
                $this->recordWaste($command, $stop, $schedule);
            }

            $this->advanceSchedule($schedule);

            return $stop->refresh();
        });
    }

    /**
     * @param  list<array{member_id: string, hours: float}>  $rows
     * @return array{members: list<array{member_id: string, hours: float}>, total: float}
     */
    private function crewHours(array $rows): array
    {
        $members = array_map(static fn (array $row): array => ['member_id' => (string) $row['member_id'], 'hours' => round((float) $row['hours'], 2)], $rows);

        return ['members' => $members, 'total' => round(array_sum(array_column($members, 'hours')), 2)];
    }

    /**
     * @param  list<array{product_id: string, quantity: float, warehouse_id?: string|null}>  $materials
     * @return list<array<string, mixed>>
     */
    private function issueMaterials(array $materials, CrewRouteStop $stop): array
    {
        $used = [];

        foreach ($materials as $material) {
            $issued = $this->stock->issue((string) $material['product_id'], (float) $material['quantity'], $material['warehouse_id'] ?? null, $stop, 'Green care intervention');

            $used[] = [
                'product_id' => (string) $issued['product']->id,
                'name' => (string) $issued['product']->name,
                'quantity' => (float) $material['quantity'],
                'unit' => (string) $issued['product']->unit_measure,
                'warehouse_id' => $issued['warehouse_id'],
                'stock_movement_id' => $issued['movement_id'],
            ];
        }

        return $used;
    }

    private function recordWaste(LogCrewSiteInterventionCommand $command, CrewRouteStop $stop, CrewRouteSchedule $schedule): void
    {
        $site = $stop->site;
        $strategy = $this->strategies->for($site->jurisdiction);
        $date = $stop->actual_checkout_at ?? now();

        GreenWasteSlip::query()->create([
            'tenant_id' => (string) $this->tenants->getTenantId(),
            'slip_number' => $this->numbering->nextWasteSlipNumber($date),
            'site_id' => $stop->site_id,
            'route_schedule_id' => $schedule->id,
            'route_stop_id' => $stop->id,
            'disposal_date' => $date->toDateString(),
            'waste_code' => $command->wasteCode ?? $strategy->defaultWasteCode(),
            'waste_description' => (string) __('green_care.waste.default_description'),
            'green_waste_weight_kg' => round((float) $command->greenWasteWeightKg, 2),
            'compost_facility_id' => $command->compostFacilityId,
            'transporter_vehicle_plate' => $schedule->assigned_vehicle_plate,
            'disposal_cost' => 0,
            'is_billable_to_customer' => false,
        ]);
    }

    /**
     * The route starts with its first check-out and ends when every stop is closed.
     */
    private function advanceSchedule(CrewRouteSchedule $schedule): void
    {
        $open = CrewRouteStop::query()->where('route_schedule_id', $schedule->id)->whereNull('actual_checkout_at')->count();
        $status = $open === 0 ? RouteStatus::Completed : RouteStatus::InProgress;

        if ($schedule->status !== $status) {
            $schedule->status = $status;
            $schedule->save();
        }
    }
}
