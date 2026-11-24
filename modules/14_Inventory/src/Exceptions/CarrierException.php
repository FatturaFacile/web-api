<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A carrier refused the request or could not be reached.
 */
final class CarrierException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 502);
    }
}
