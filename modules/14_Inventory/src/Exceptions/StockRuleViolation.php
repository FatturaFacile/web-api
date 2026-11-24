<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A logistics rule refused the operation: the request was well formed but the stock does not allow it.
 */
final class StockRuleViolation extends InvalidArgumentException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
