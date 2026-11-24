<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\User;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Analytics\Models\ReportDefinition;
use Modules\Analytics\Support\ReportPeriod;

/**
 * Runs a saved report definition: presets are resolved on the calendar of the company at the moment of the run, so a
 * "month to date" report always means the current month.
 */
final readonly class ReportDefinitionRunner
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private KpiCatalog $catalog, private TenantClock $clock) {}

    /**
     * The period the definition covers today.
     *
     * @throws ValidationException when a custom period is longer than allowed
     */
    public function periodOf(ReportDefinition $definition): ReportPeriod
    {
        $today = $this->clock->today();
        [$from, $to] = match ($definition->period_preset) {
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'quarter_to_date' => [$today->startOfQuarter(), $today],
            'year_to_date' => [$today->startOfYear(), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'custom' => [CarbonImmutable::parse((string) $definition->from_date), CarbonImmutable::parse((string) $definition->to_date)],
            default => [$today->startOfMonth(), $today],
        };

        try {
            return new ReportPeriod($from->startOfDay(), $to->startOfDay(), (string) $definition->compare);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['period' => $exception->getMessage()]);
        }
    }

    /**
     * Calculate the report of a definition for the caller.
     *
     * @return array{period: array{from: string, to: string}, comparison_period: ?array{from: string, to: string}, kpis: list<array<string, mixed>>}
     */
    public function run(ReportDefinition $definition, User $user): array
    {
        return $this->catalog->report($definition->kpis, $this->periodOf($definition), $user);
    }
}
