<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Queries;

final readonly class PreviewMonthlyBillingQuery
{
    public function __construct(public string $contractId, public string $periodStart, public string $periodEnd) {}
}
