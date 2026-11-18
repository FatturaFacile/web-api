<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A combination of Italian fiscal options that cannot be invoiced (e.g. withholding under the flat-rate regime).
 * Rendered as a 422 validation error that also carries a machine-readable `code`.
 */
class FiscalRuleException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $field,
        array $replace = [],
    ) {
        parent::__construct((string) __('tax.errors.'.$errorCode, $replace));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => [$this->field => [$this->getMessage()]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
