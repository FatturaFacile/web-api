<?php

declare(strict_types=1);

namespace Modules\Customs\Exceptions;

use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customs rule refused the operation: rendered as RFC 7807.
 */
class CustomsException extends DomainException
{
    use ApiResponse;

    public function render(): JsonResponse
    {
        return $this->problemDetailsResponse('Customs Rule Violation', $this->getMessage(), $this->status());
    }

    protected function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
