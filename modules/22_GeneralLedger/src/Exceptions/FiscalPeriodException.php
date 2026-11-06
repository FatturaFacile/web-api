<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A document cannot reach the ledger because of its fiscal period. It always stops the whole operation, so that a
 * fiscal document never exists without its entry.
 */
final class FiscalPeriodException extends InvalidArgumentException
{
    public static function missing(string $date): self
    {
        return new self("No fiscal period contains the date {$date}: open the fiscal year before posting to it.");
    }

    public static function closed(string $periodName, string $date): self
    {
        return new self("Fiscal period [{$periodName}] containing the date {$date} is closed: the document cannot be posted to it.");
    }

    public static function dateOutsidePeriod(string $date, string $periodName): self
    {
        return new self("Posting date {$date} is outside fiscal period [{$periodName}].");
    }

    public static function foreignPeriod(string $periodName): self
    {
        return new self("Fiscal period [{$periodName}] does not belong to this tenant.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['fiscal_period' => [$this->getMessage()]],
        ], 422);
    }
}
