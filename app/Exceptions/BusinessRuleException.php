<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A business rule of a vertical module refused the operation: rendered as RFC 7807 with status 422.
 */
class BusinessRuleException extends DomainException
{
    use ApiResponse;

    public function render(): JsonResponse
    {
        return $this->problemDetailsResponse('Business Rule Violation', $this->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
