<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Queries;

final readonly class GetEmployeeCedolinoDownloadAuditQuery
{
    public function __construct(public string $periodId, public ?string $employeeId = null) {}
}
