<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions;

use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Human Resources rule refused the operation: rendered as RFC 7807 problem details (422 unless a subclass says
 * otherwise).
 */
class HumanResourcesException extends DomainException
{
    use ApiResponse;

    /**
     * The problem details response of the refusal.
     */
    public function render(): JsonResponse
    {
        return $this->problemDetailsResponse('Human Resources Rule Violation', $this->getMessage(), $this->status());
    }

    protected function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
