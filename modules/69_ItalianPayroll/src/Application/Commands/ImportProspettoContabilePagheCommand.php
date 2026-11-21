<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Commands;

final readonly class ImportProspettoContabilePagheCommand
{
    /**
     * @param  array{gross: string, inps_employee: string, inps_employer: string, irpef: string, addizionali: string, tfr_accrual: string, tfr_fund: string, net: string, inail?: string}  $summary
     * @param  list<array{employee_id: string, net: string}>  $employeeNets  the net of each payslip; their sum must equal the net payable
     */
    public function __construct(public string $periodId, public array $summary, public array $employeeNets, public ?string $actorId, public ?string $companyId = null) {}
}
