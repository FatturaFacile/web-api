<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use Illuminate\Support\Carbon;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;

/**
 * An ERP event that may trigger a survey: what happened, to which customer, which record it came from and the dimensions
 * (department, branch, product line, technician, customer tier, amount) used to filter the rules and break the results down.
 */
final readonly class SurveyTriggerContext
{
    /**
     * @param  array<string, scalar|null>  $dimensions  department_code, branch_code, product_line_code, technician_id, customer_tier, amount
     */
    public function __construct(
        public SurveyTriggerEvent $event,
        public string $customerId,
        public ?string $sourceEntityType = null,
        public ?string $sourceEntityId = null,
        public ?string $locale = null,
        public array $dimensions = [],
        public ?Carbon $occurredAt = null,
    ) {}
}
