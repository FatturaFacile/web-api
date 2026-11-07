<?php

declare(strict_types=1);

namespace Modules\Procurement\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * The request was well formed but the purchasing data does not allow it.
 */
final class ProcurementRuleViolation extends InvalidArgumentException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
