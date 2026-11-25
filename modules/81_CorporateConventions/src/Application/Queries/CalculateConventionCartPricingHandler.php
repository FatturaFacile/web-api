<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Queries;

use Modules\CorporateConventions\Domain\ValueObjects\PricingResult;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Services\ConventionPricingService;
use Modules\CorporateConventions\Services\EnrollmentService;

/**
 * Prices a cart under the convention of a pass: discounts, remaining cap and the employer / employee split.
 */
final class CalculateConventionCartPricingHandler
{
    public function __construct(
        private readonly ConventionPricingService $pricing,
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * @return array{enrollment: ConventionBeneficiaryEnrollment, pricing: PricingResult}
     */
    public function handle(CalculateConventionCartPricingQuery $query): array
    {
        $enrollment = $this->enrollments->resolveUsable($query->identifier, $query->conventionId);
        $convention = $enrollment->convention;

        return [
            'enrollment' => $enrollment,
            'pricing' => $this->pricing->price($convention, $enrollment, $query->lines, $query->currency),
        ];
    }
}
