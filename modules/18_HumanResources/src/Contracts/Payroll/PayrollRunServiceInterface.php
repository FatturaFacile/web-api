<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts\Payroll;

use Modules\HumanResources\DTOs\Payroll\CreatePayrollRunDTO;
use Modules\HumanResources\DTOs\Payroll\UpdatePayrollRunDTO;
use Modules\HumanResources\Exceptions\Payroll\PayrollCalculationException;
use Modules\HumanResources\Exceptions\Payroll\PayrollRunStateException;
use Modules\HumanResources\Models\Payroll\PayrollRun;

interface PayrollRunServiceInterface
{
    public function createRun(CreatePayrollRunDTO $dto, ?string $actorUserId = null): PayrollRun;

    /**
     * @throws PayrollRunStateException
     */
    public function updateRun(PayrollRun $run, UpdatePayrollRunDTO $dto, ?string $actorUserId = null): PayrollRun;

    /**
     * @throws PayrollRunStateException
     * @throws PayrollCalculationException
     */
    public function calculate(PayrollRun $run, ?string $actorUserId = null): PayrollRun;

    /**
     * @throws PayrollRunStateException
     */
    public function approve(PayrollRun $run, ?string $actorUserId = null): PayrollRun;

    /**
     * @throws PayrollRunStateException
     */
    public function close(PayrollRun $run, ?string $actorUserId = null): PayrollRun;
}
