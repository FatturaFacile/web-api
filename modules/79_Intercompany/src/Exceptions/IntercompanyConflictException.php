<?php

declare(strict_types=1);

namespace Modules\Intercompany\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The request collides with the current state: a stale `expected_version`, a locked run, an illegal transition (HTTP 409).
 */
final class IntercompanyConflictException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'conflict')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['intercompany' => [$this->getMessage()]],
            'error_code' => 'intercompany.error.'.$this->errorCode,
        ], 409);
    }
}
