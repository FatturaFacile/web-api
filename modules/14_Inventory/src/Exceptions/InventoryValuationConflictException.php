<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * State conflict on a valuation document (e.g. landed cost already applied, export period still open).
 * Rendered as HTTP 409 with a Laravel-style `{message}` body.
 */
final class InventoryValuationConflictException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
