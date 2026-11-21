<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Queries;

final readonly class GetF24PayrollTaxSummaryQuery
{
    public function __construct(public string $periodId) {}
}
