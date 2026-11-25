<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule of the dunning module was refused. The `code` is a translation key of the frontend dictionary
 * (debtCollection.error.<code>); the message is the English fallback. Rendered as an RFC 7807 problem.
 */
final class DebtCollectionException extends RuntimeException
{
    public function __construct(string $message, private readonly string $errorCode = 'refused', private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => $this->errorCode,
            'status' => $this->status,
            'detail' => $this->getMessage(),
            'instance' => $request->getRequestUri(),
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'success' => false,
        ], $this->status);
    }
}
