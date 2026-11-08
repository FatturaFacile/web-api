<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A lifecycle, depreciation or maintenance operation the current state of the record does not allow (already disposed,
 * locked by a workflow, no open fiscal period...). Rendered as a 422 validation error on the given field.
 */
final class AssetOperationException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $field = 'asset')
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
