<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Compliance;

use Modules\IndustrialLaundry\Models\Garment;

/**
 * A compliance rule set of the industrial laundry (the "IIndustrialLaundryComplianceStrategy" of the specification). A strategy
 * answers the three questions the module asks: may this garment be issued again, was this wash batch hygienic, and what does
 * the invoice of this period have to carry. Every strategy implements all three and answers "nothing to say" for the ones that
 * are not its concern, so the registry can run them all.
 */
interface IndustrialLaundryComplianceStrategyInterface
{
    public function key(): string;

    /**
     * @param  int  $projectedWashCycles  Wash cycles of the garment once the wash that is being closed is counted.
     * @return list<ComplianceFinding>
     */
    public function evaluateGarment(Garment $garment, int $projectedWashCycles): array;

    /**
     * @return list<ComplianceFinding>
     */
    public function evaluateWashBatch(WashBatchMeasurements $measurements): array;

    public function decorateBilling(BillingContext $context, BillingDecoration $decoration): BillingDecoration;
}
