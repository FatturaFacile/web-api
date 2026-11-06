<?php

declare(strict_types=1);

namespace Modules\Associations\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A business rule of the association said no. It renders itself, so a service can refuse from anywhere.
 */
class AssociationException extends RuntimeException
{
    use ApiResponse;

    public function __construct(string $message, private readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY)
    {
        parent::__construct($message);
    }

    public static function conflict(string $message): self
    {
        return new self($message, Response::HTTP_CONFLICT);
    }

    public function render(): JsonResponse
    {
        return $this->problemDetailsResponse(title: 'Association Rule Violation', detail: $this->getMessage(), status: $this->status);
    }
}
