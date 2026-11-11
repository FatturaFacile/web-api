<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A consolidation cannot be built or configured as asked: a missing exchange rate, a method that is not implemented, a
 * mapping that does not fit the accounts. The data is left untouched; the client corrects the input and asks again.
 */
final class ConsolidationException extends InvalidArgumentException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['consolidation' => [$this->getMessage()]],
        ], 422);
    }
}
