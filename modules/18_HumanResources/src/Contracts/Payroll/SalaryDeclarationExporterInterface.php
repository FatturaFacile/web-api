<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts\Payroll;

use Modules\HumanResources\DTOs\Payroll\GenerateSalaryDeclarationDTO;
use Modules\HumanResources\Exceptions\Payroll\SalaryDeclarationException;
use Modules\HumanResources\Models\Payroll\SalaryDeclaration;

interface SalaryDeclarationExporterInterface
{
    /**
     * Builds the ELM-style XML for the approved/closed runs of a year, stores it and records its hash.
     *
     * @throws SalaryDeclarationException
     */
    public function generate(GenerateSalaryDeclarationDTO $dto): SalaryDeclaration;
}
