<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A document lacks a fact the ledger needs. Nothing is assumed in its place: the operation is refused.
 */
final class LedgerPostingException extends InvalidArgumentException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['ledger' => [$this->getMessage()]],
        ], 422);
    }
}
