<?php

declare(strict_types=1);

namespace Modules\Payroll\Swissdec;

use Modules\Payroll\Exceptions\PayrollException;
use Symfony\Component\HttpFoundation\Response;

/**
 * No transmission to Swissdec exists in this application.
 */
final class SwissdecTransmissionUnavailable extends PayrollException
{
    protected function status(): int
    {
        return Response::HTTP_NOT_IMPLEMENTED;
    }
}
