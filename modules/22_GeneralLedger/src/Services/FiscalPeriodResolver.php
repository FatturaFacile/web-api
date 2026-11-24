<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Models\FiscalPeriod;

/**
 * Finds the fiscal period a document belongs to: the one containing its date. There is no fallback to another open
 * period, because that would book the document in the wrong month.
 */
final class FiscalPeriodResolver
{
    /**
     * The day a domain event happened. Not every event of the older modules exposes it through the contract.
     */
    public function dateOfEvent(object $event): string
    {
        $occurredAt = method_exists($event, 'getOccurredAt') ? $event->getOccurredAt() : ($event->occurredAt ?? null);

        return CarbonImmutable::parse($occurredAt ?: now())->toDateString();
    }

    /**
     * @throws FiscalPeriodException when no period contains the date or the period is not open
     */
    public function forDate(string $tenantId, string|DateTimeInterface $date, string $subledger = 'GL'): FiscalPeriod
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        $periods = FiscalPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->orderBy('period_number')
            ->get();

        if ($periods->isEmpty()) {
            throw FiscalPeriodException::missing($day);
        }

        $open = $periods->first(static fn (FiscalPeriod $period): bool => $period->status === 'open' && ! $period->isLockedFor($subledger));

        return $open ?? throw FiscalPeriodException::closed((string) $periods->first()->period_name, $day);
    }
}
