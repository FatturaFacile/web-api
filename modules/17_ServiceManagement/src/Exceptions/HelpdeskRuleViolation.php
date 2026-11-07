<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * The request was well formed but the state of the ticket does not allow it.
 */
final class HelpdeskRuleViolation extends InvalidArgumentException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
