<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\ProductionEngineering;

use Modules\Manufacturing\Enums\OperationType;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgRoutingStepBomComponent;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Support\Money;

/**
 * Writes the ordered operations of a routing with their BOM component links. Sequences ascend in the submitted order
 * (10, 20, 30... when not given), the machine belongs to the work centre, alternates are other work centres of the
 * company, a subcontracted operation carries its unit cost and linked components belong to the BOM of the routing.
 */
final readonly class RoutingOperationWriter
{
    /**
     * Replaces nothing: callers delete the previous operations first when they redefine a routing.
     *
     * @param  list<array<string, mixed>>  $operations
     */
    public function write(string $tenantId, MfgRouting $routing, array $operations): void
    {
        $sequence = 0;
        $default = 10;

        foreach ($operations as $operation) {
            $given = isset($operation['sequence']) ? (int) $operation['sequence'] : $default;
            if ($given <= $sequence) {
                throw new ManufacturingOperationException("Operation sequences must ascend: [{$given}] follows [{$sequence}].", 'operations');
            }
            $sequence = $given;
            $default = $given + 10;

            $this->writeOperation($tenantId, $routing, $operation, $given);
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function writeOperation(string $tenantId, MfgRouting $routing, array $operation, int $sequence): void
    {
        /** @var MfgWorkCenter $workCenter */
        $workCenter = MfgWorkCenter::query()->where('tenant_id', $tenantId)->findOrFail($operation['work_center_id']);
        $machineId = $operation['machine_center_id'] ?? $operation['preferred_machine_center_id'] ?? null;
        if ($machineId !== null && ! MfgMachineCenter::query()->where('tenant_id', $tenantId)->where('work_center_id', $workCenter->id)->whereKey($machineId)->exists()) {
            throw new ManufacturingOperationException('The machine does not belong to the work centre of the operation.', 'operations');
        }

        $type = OperationType::tryFrom((string) ($operation['operation_type'] ?? ''))
            ?? ((bool) ($operation['is_subcontracted'] ?? false) ? OperationType::ExternalSubcontracting : OperationType::InternalMachining);
        $subcontracted = $type->isSubcontracted();
        if ($subcontracted && ! isset($operation['subcontract_unit_cost'])) {
            throw new ManufacturingOperationException('A subcontracted operation needs its unit cost.', 'operations');
        }

        $alternates = $this->alternates($tenantId, $operation['alternate_work_center_ids'] ?? null, (string) $workCenter->id);
        $overlap = $operation['overlap_transfer_batch_qty'] ?? null;

        /** @var MfgOperation $created */
        $created = MfgOperation::create([
            'tenant_id' => $tenantId,
            'routing_id' => $routing->id,
            'sequence' => $sequence,
            'operation_code' => $operation['operation_code'],
            'name' => $operation['name'] ?? $operation['title'] ?? $operation['operation_code'],
            'description' => $operation['description'] ?? null,
            'work_center_id' => $workCenter->id,
            'machine_center_id' => $machineId,
            'operation_type' => $type->value,
            'setup_time_minutes' => Money::round($operation['setup_time_minutes'] ?? '0', 3),
            'run_time_minutes_per_unit' => Money::round($operation['run_time_minutes_per_unit'] ?? '0', 5),
            'labor_time_minutes_per_unit' => Money::round($operation['labor_time_minutes_per_unit'] ?? '0', 2),
            'queue_time_minutes' => Money::round($operation['queue_time_minutes'] ?? $operation['queue_wait_time_minutes'] ?? '0', 3),
            'wait_time_minutes' => Money::round($operation['wait_time_minutes'] ?? '0', 2),
            'teardown_time_minutes' => Money::round($operation['teardown_time_minutes'] ?? '0', 2),
            'post_operation_wait_minutes' => Money::round($operation['post_operation_wait_minutes'] ?? '0', 3),
            'move_time_minutes' => Money::round($operation['move_time_minutes'] ?? '0', 3),
            'overlap_transfer_batch_qty' => $overlap === null ? null : Money::round($overlap, 4),
            'required_operators' => max(1, (int) ($operation['required_operators'] ?? 1)),
            'alternate_work_center_ids' => $alternates,
            'standard_labor_rate' => $workCenter->hourly_cost_rate,
            'standard_machine_rate' => $workCenter->hourly_overhead_rate,
            'is_subcontracted' => $subcontracted,
            'subcontractor_contact_id' => $operation['subcontractor_contact_id'] ?? $operation['subcontracting_vendor_id'] ?? null,
            'subcontract_unit_cost' => Money::round($operation['subcontract_unit_cost'] ?? '0', 4),
            'quality_plan_id' => $operation['quality_plan_id'] ?? null,
            'linked_quality_control_point_id' => $operation['linked_quality_control_point_id'] ?? null,
            'work_instruction_doc_id' => $operation['work_instruction_doc_id'] ?? null,
        ]);

        foreach ((array) ($operation['bom_components'] ?? []) as $link) {
            $this->link($tenantId, $routing, $created, (array) $link);
        }
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private function link(string $tenantId, MfgRouting $routing, MfgOperation $operation, array $link): void
    {
        if ($routing->bom_id === null) {
            throw new ManufacturingOperationException('Components can be linked only when the routing has a BOM.', 'operations');
        }

        $exists = MfgBomComponent::query()->where('tenant_id', $tenantId)->where('bom_id', $routing->bom_id)->whereKey($link['bom_component_id'])->exists();
        if (! $exists) {
            throw new ManufacturingOperationException('A linked component does not belong to the BOM of the routing.', 'operations');
        }

        MfgRoutingStepBomComponent::create([
            'tenant_id' => $tenantId,
            'operation_id' => $operation->id,
            'bom_component_id' => $link['bom_component_id'],
            'backflush_on_completion' => (bool) ($link['backflush_on_completion'] ?? true),
            'notes' => $link['notes'] ?? null,
        ]);
    }

    /**
     * Ordered alternate work centres of the company, other than the work centre itself.
     *
     * @return list<string>|null
     */
    public function alternates(string $tenantId, mixed $ids, string $ownId): ?array
    {
        if ($ids === null || $ids === []) {
            return null;
        }

        $ids = array_values(array_unique(array_map('strval', (array) $ids)));
        if (in_array($ownId, $ids, true)) {
            throw new ManufacturingOperationException('A work centre cannot be its own alternate.', 'alternate_work_center_ids');
        }
        if (MfgWorkCenter::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw new ManufacturingOperationException('An alternate work centre does not exist.', 'alternate_work_center_ids');
        }

        return $ids;
    }
}
