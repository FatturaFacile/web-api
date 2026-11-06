<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Refused withholding settlement operation: rendered as the standard error envelope with its own HTTP status.
 */
final class WithholdingSettlementException extends InvalidArgumentException
{
    private function __construct(string $message, private readonly int $status, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422, 'WITHHOLDING_SETTLEMENT_INVALID');
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409, 'WITHHOLDING_SETTLEMENT_CONFLICT');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->status);
    }
}
