<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions\Payroll;

use Modules\HumanResources\Enums\Payroll\PayrollRunStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * The requested transition is not allowed in the current run status (e.g. recalculating a closed run): 409.
 */
final class PayrollRunStateException extends PayrollException
{
    public static function cannot(string $action, PayrollRunStatus $status): self
    {
        return new self(
            detail: "A payroll run in status '{$status->value}' cannot be {$action}.",
            invalidParams: [],
            status: Response::HTTP_CONFLICT,
            title: 'Payroll Run State Conflict',
        );
    }
}
