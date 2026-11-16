<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

final readonly class GenerateSalaryDeclarationDTO
{
    /**
     * @param  list<string>|null  $payrollRunIds  restrict to these runs (null = every approved or closed run of the year)
     */
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public int $year,
        public ?array $payrollRunIds = null,
        public ?string $actorUserId = null,
    ) {}
}
