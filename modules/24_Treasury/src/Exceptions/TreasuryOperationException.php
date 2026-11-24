<?php

declare(strict_types=1);

namespace Modules\Treasury\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A treasury operation the configuration or the state of the records does not allow: a ledger account that is not
 * configured, a bank account without its ledger account, a closed fiscal period, a proposal approved by its author...
 * Rendered as a 422 validation error on the given field.
 */
final class TreasuryOperationException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field = 'treasury')
    {
        parent::__construct($message);
    }

    /**
     * JSON response in the shape of a validation error.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [$this->field => [$this->getMessage()]],
        ], 422);
    }
}
