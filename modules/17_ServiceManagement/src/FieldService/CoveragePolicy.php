<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService;

use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\WorkOrder;

/**
 * Decides what a maintenance contract or a warranty covers (billed at 0.00) and what stays billable.
 *
 *  - warranty work orders: everything is covered;
 *  - labor_only: labor is covered, parts, travel and callout are billable;
 *  - parts_and_labor: labor and parts are covered, travel and callout are billable;
 *  - preventive_only: preventive work orders are covered like parts_and_labor, corrective ones are fully billable;
 *  - full_comprehensive: labor, parts, travel and callout are all covered.
 */
final class CoveragePolicy
{
    public const LaborOnly = 'labor_only';

    public const PartsAndLabor = 'parts_and_labor';

    public const PreventiveOnly = 'preventive_only';

    public const FullComprehensive = 'full_comprehensive';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::LaborOnly, self::PartsAndLabor, self::PreventiveOnly, self::FullComprehensive];
    }

    public function coversLabor(WorkOrder $workOrder, ?ServiceContract $contract): bool
    {
        return $this->isWarranty($workOrder) || $this->activeCoverage($workOrder, $contract) !== null;
    }

    public function coversParts(WorkOrder $workOrder, ?ServiceContract $contract): bool
    {
        if ($this->isWarranty($workOrder)) {
            return true;
        }

        return in_array($this->activeCoverage($workOrder, $contract), [self::PartsAndLabor, self::PreventiveOnly, self::FullComprehensive], true);
    }

    public function coversTravelAndCallout(WorkOrder $workOrder, ?ServiceContract $contract): bool
    {
        return $this->isWarranty($workOrder) || $this->activeCoverage($workOrder, $contract) === self::FullComprehensive;
    }

    private function isWarranty(WorkOrder $workOrder): bool
    {
        return $workOrder->work_type === 'warranty';
    }

    /**
     * The coverage type that applies to this work order, or null when the contract is missing, not active or does not
     * cover this kind of work (preventive_only covers preventive work orders only).
     */
    private function activeCoverage(WorkOrder $workOrder, ?ServiceContract $contract): ?string
    {
        if ($contract === null || $contract->status !== 'active') {
            return null;
        }

        $coverage = (string) ($contract->coverage_type ?? self::PartsAndLabor);

        if ($coverage === self::PreventiveOnly && $workOrder->work_type !== 'preventive') {
            return null;
        }

        return $coverage;
    }
}
