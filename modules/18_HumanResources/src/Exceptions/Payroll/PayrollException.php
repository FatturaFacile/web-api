<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions\Payroll;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class of Swiss payroll domain errors. Rendered as RFC 7807 Problem Details by the exception handler.
 */
class PayrollException extends RuntimeException
{
    use ApiResponse;

    /**
     * @param  array<string, list<string>>  $invalidParams
     */
    public function __construct(
        string $detail,
        private readonly array $invalidParams = [],
        private readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
        private readonly string $title = 'Unprocessable Entity',
    ) {
        parent::__construct($detail);
    }

    /**
     * @return array<string, list<string>>
     */
    public function invalidParams(): array
    {
        return $this->invalidParams;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function render(Request $request): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: $this->title,
            detail: $this->getMessage(),
            status: $this->status,
            instance: $request->getRequestUri(),
            invalidParams: $this->invalidParams,
        );
    }
}
