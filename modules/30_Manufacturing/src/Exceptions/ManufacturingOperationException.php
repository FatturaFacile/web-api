<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * An operation the current state of a manufacturing record does not allow (order not released, quantity above the
 * open quantity, no open fiscal period, missing stock...). Rendered as a 422 validation error on the given field.
 */
final class ManufacturingOperationException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field = 'manufacturing')
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
