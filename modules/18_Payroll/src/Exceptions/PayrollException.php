<?php

declare(strict_types=1);

namespace Modules\Payroll\Exceptions;

use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A payroll rule refused the operation (the InvalidOperationException of the desktop): rendered as RFC 7807.
 */
class PayrollException extends DomainException
{
    use ApiResponse;

    public function render(): JsonResponse
    {
        return $this->problemDetailsResponse('Payroll Rule Violation', $this->getMessage(), $this->status());
    }

    protected function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
