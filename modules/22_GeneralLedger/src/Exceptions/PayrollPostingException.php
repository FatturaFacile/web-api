<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A payroll run cannot be posted as things stand. The run stays closed and can be posted later.
 */
final class PayrollPostingException extends InvalidArgumentException
{
    public static function missingAccount(string $role): self
    {
        return new self("Payroll posting refused: configure the {$role} account in the payroll ledger settings.");
    }

    public static function unmappedWageType(string $code, string $kind): self
    {
        return new self("Payroll posting refused: wage type {$code} ({$kind}) has no account. Map it in the payroll ledger settings.");
    }

    public static function foreignCurrency(string $currency, string $accountingCurrency): self
    {
        return new self("Payroll posting refused: the run is in {$currency}, the accounting currency is {$accountingCurrency}.");
    }

    public static function unbalanced(string $difference): self
    {
        return new self("Payroll posting refused: the run does not balance, difference {$difference}.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['payroll_posting' => [$this->getMessage()]],
        ], 422);
    }
}
