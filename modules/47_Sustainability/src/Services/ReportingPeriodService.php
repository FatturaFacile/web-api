<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Sustainability\Models\EsgReportingPeriod;

/**
 * Reporting periods: open while data is collected, closed to lock the activity data and the KPI values of the period
 * (reopening is reserved to approvers by the policy). Periods of a company never overlap.
 */
final readonly class ReportingPeriodService
{
    public function __construct(private SustainabilityLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): EsgReportingPeriod
    {
        $this->assertNoOverlap($tenantId, (string) $data['starts_on'], (string) $data['ends_on'], null);

        return EsgReportingPeriod::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'status' => EsgReportingPeriod::Open,
            'revenue' => $data['revenue'] ?? null,
            'currency' => $data['currency'] ?? 'EUR',
            'employees_fte' => $data['employees_fte'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EsgReportingPeriod $period, array $data): EsgReportingPeriod
    {
        $this->assertOpen($period);
        $starts = (string) ($data['starts_on'] ?? $period->starts_on->toDateString());
        $ends = (string) ($data['ends_on'] ?? $period->ends_on->toDateString());
        $this->assertNoOverlap((string) $period->tenant_id, $starts, $ends, $period->id);

        $period->update(array_intersect_key($data, array_flip(['name', 'starts_on', 'ends_on', 'revenue', 'currency', 'employees_fte'])));

        return $period->refresh();
    }

    public function close(EsgReportingPeriod $period, ?string $actorId): EsgReportingPeriod
    {
        return $this->transition($period, EsgReportingPeriod::Open, EsgReportingPeriod::Closed, 'ESG_PERIOD_CLOSED', [
            'closed_at' => now(),
            'closed_by' => $actorId,
        ]);
    }

    public function reopen(EsgReportingPeriod $period): EsgReportingPeriod
    {
        return $this->transition($period, EsgReportingPeriod::Closed, EsgReportingPeriod::Open, 'ESG_PERIOD_REOPENED', [
            'closed_at' => null,
            'closed_by' => null,
        ]);
    }

    /**
     * The closed period that contains the date, if any.
     */
    public function closedPeriodOn(string $tenantId, string $date): ?EsgReportingPeriod
    {
        return EsgReportingPeriod::query()
            ->where('tenant_id', $tenantId)
            ->where('status', EsgReportingPeriod::Closed)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function transition(EsgReportingPeriod $period, string $from, string $to, string $event, array $changes): EsgReportingPeriod
    {
        return DB::transaction(function () use ($period, $from, $to, $event, $changes): EsgReportingPeriod {
            $locked = EsgReportingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                throw ValidationException::withMessages(['status' => [__('sustainability_errors.period.status_expected', ['status' => $locked->status, 'expected' => $from])]]);
            }

            $locked->update(['status' => $to, ...$changes]);
            $this->ledger->record($event, $locked, ['starts_on' => $locked->starts_on->toDateString(), 'ends_on' => $locked->ends_on->toDateString()]);

            return $locked->refresh();
        });
    }

    private function assertOpen(EsgReportingPeriod $period): void
    {
        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['status' => [__('sustainability_errors.period.closed', ['name' => $period->name])]]);
        }
    }

    private function assertNoOverlap(string $tenantId, string $starts, string $ends, ?string $ignoreId): void
    {
        $other = EsgReportingPeriod::query()
            ->where('tenant_id', $tenantId)
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->whereDate('starts_on', '<=', $ends)
            ->whereDate('ends_on', '>=', $starts)
            ->first();

        if ($other !== null) {
            throw ValidationException::withMessages(['starts_on' => [__('sustainability_errors.period.overlap', ['name' => $other->name])]]);
        }
    }
}
