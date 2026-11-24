<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Domain error of the maintenance-contract hour bank, rendered as `{"message","code"}` with its HTTP status.
 */
final class HourBankException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function insufficientBalance(string $remaining, string $requested): self
    {
        return new self(
            sprintf('Insufficient hour-bank balance: %s h remaining, %s h requested.', $remaining, $requested),
            'insufficient_hour_balance',
        );
    }

    public static function contractNotActive(string $status): self
    {
        return new self(sprintf('The maintenance contract is "%s", hours can only be booked on active contracts.', $status), 'contract_not_active', 409);
    }

    public static function invalidAmount(): self
    {
        return new self('Hours must be a non-zero decimal with at most two decimals.', 'invalid_hours');
    }

    public static function noRecipient(): self
    {
        return new self('No e-mail address available for the residual hours report.', 'no_report_recipient');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
