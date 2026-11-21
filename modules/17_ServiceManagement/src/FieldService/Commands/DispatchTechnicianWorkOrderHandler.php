<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Hse\Domain\Contracts\HseDispatchGuardInterface;
use Modules\Hse\Domain\Enums\ClearanceContext;
use Modules\ServiceManagement\FieldService\VanWarehouseResolver;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Dispatches a work order to a technician after three checks: no overlapping dispatch, the required skills (Mod 079
 * technician profile) and the required spare parts in the technician's van warehouse (Mod 051). Every failed check is
 * reported together, under its own key, so the dispatcher sees all the blockers at once.
 */
final readonly class DispatchTechnicianWorkOrderHandler
{
    /** Statuses of a work order that still occupy the technician's calendar. */
    public const BusyStatuses = ['scheduled', 'dispatched', 'en_route', 'on_site', 'in_progress'];

    public function __construct(
        private VanWarehouseResolver $vans,
        private AuditLedgerInterface $auditLedger,
        private HseDispatchGuardInterface $hse,
    ) {}

    public function __invoke(DispatchTechnicianWorkOrderCommand $command): WorkOrder
    {
        return DB::transaction(function () use ($command): WorkOrder {
            $workOrder = WorkOrder::query()->whereKey($command->workOrderId)->lockForUpdate()->firstOrFail();
            $technician = ServiceTechnician::query()->where('tenant_id', $workOrder->tenant_id)->findOrFail($command->technicianId);

            if (! in_array($workOrder->status, ['draft', 'unscheduled', 'scheduled', 'dispatched'], true)) {
                throw ValidationException::withMessages(['work_order' => __('field_service.errors.not_dispatchable')]);
            }

            if ($command->scheduledEnd->lessThanOrEqualTo($command->scheduledStart)) {
                throw ValidationException::withMessages(['scheduled_end' => __('field_service.errors.window_invalid')]);
            }

            $errors = [];

            if (! $technician->is_available) {
                $errors['technician'][] = __('field_service.errors.technician_unavailable');
            }

            if ($this->hasOverlap($workOrder, $technician, $command)) {
                $errors['schedule'][] = __('field_service.errors.schedule_conflict');
            }

            $missingSkills = $this->missingSkills((array) $workOrder->required_skills, $technician);

            if ($missingSkills !== []) {
                $errors['skills'][] = __('field_service.errors.skills_missing', ['skills' => implode(', ', $missingSkills)]);
            }

            $requiredParts = array_values((array) $workOrder->required_parts);

            if ($requiredParts !== []) {
                $van = $this->vans->forTechnician($technician);

                if ($van === null) {
                    $errors['van_stock'][] = __('field_service.errors.no_van');
                } elseif ($this->vans->shortfall($van, $requiredParts) !== []) {
                    $errors['van_stock'][] = __('field_service.errors.van_stock_short');
                }
            }

            try {
                // Mod 099 hard block: certificates, medical fitness and PPE of the technician as of the visit.
                $this->hse->assertCleared(
                    (string) $workOrder->tenant_id,
                    $technician->employee_id !== null ? (string) $technician->employee_id : null,
                    $technician->user_id !== null ? (string) $technician->user_id : null,
                    ClearanceContext::ExternalFieldService,
                    (string) $workOrder->id,
                    CarbonImmutable::instance($command->scheduledStart),
                );
            } catch (ValidationException $hseBlock) {
                $errors['hse'] = $hseBlock->errors()['hse'] ?? [];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $old = ['status' => $workOrder->status, 'assigned_technician_id' => $workOrder->assigned_technician_id];

            $workOrder->update([
                'status' => 'dispatched',
                'assigned_technician_id' => $technician->id,
                'scheduled_start' => $command->scheduledStart,
                'scheduled_end' => $command->scheduledEnd,
            ]);

            $this->auditLedger->record('WORK_ORDER_DISPATCHED', $workOrder, $old, ['technician_id' => (string) $technician->id], 'MODULE_17_ESM');

            return $workOrder->refresh();
        });
    }

    private function hasOverlap(WorkOrder $workOrder, ServiceTechnician $technician, DispatchTechnicianWorkOrderCommand $command): bool
    {
        return WorkOrder::query()
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('assigned_technician_id', $technician->id)
            ->whereKeyNot($workOrder->id)
            ->whereIn('status', self::BusyStatuses)
            ->where('scheduled_start', '<', $command->scheduledEnd)
            ->where('scheduled_end', '>', $command->scheduledStart)
            ->exists();
    }

    /**
     * @param  array<int, mixed>  $required
     * @return list<string>
     */
    private function missingSkills(array $required, ServiceTechnician $technician): array
    {
        $held = self::normalizeSkills((array) $technician->skills);
        $missing = [];

        foreach (self::normalizeSkills($required) as $skill) {
            if (! in_array($skill, $held, true)) {
                $missing[] = $skill;
            }
        }

        return $missing;
    }

    /**
     * Skills are stored either as a list of names or of objects with a name; both compare case-insensitively.
     *
     * @param  array<int|string, mixed>  $skills
     * @return list<string>
     */
    public static function normalizeSkills(array $skills): array
    {
        $names = [];

        foreach ($skills as $key => $skill) {
            $name = is_array($skill) ? ($skill['name'] ?? $skill['skill'] ?? null) : (is_string($key) && $skill === true ? $key : $skill);

            if (is_string($name) && trim($name) !== '') {
                $names[] = mb_strtolower(trim($name));
            }
        }

        return array_values(array_unique($names));
    }
}
