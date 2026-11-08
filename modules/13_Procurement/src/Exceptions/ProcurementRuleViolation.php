<?php

declare(strict_types=1);

namespace Modules\Procurement\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * The request was well formed but the purchasing data does not allow it (wrong status, quantity beyond tolerance,
 * supplier not qualified...). Rendered as a 422 validation error on the given field.
 */
final class ProcurementRuleViolation extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field = 'procurement')
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
