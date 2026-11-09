<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * The request is valid but the ledger object is not in a state that allows it: an entry already posted, a journal
 * waiting for another approver, an account that already carries postings. The client must reload and decide again.
 */
final class LedgerConflictException extends InvalidArgumentException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['ledger' => [$this->getMessage()]],
        ], 409);
    }
}
