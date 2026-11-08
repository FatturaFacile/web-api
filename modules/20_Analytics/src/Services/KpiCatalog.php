<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use InvalidArgumentException;
use Modules\Analytics\Contracts\KpiCalculator;
use Modules\Analytics\Kpis\HeadcountKpi;
use Modules\Analytics\Kpis\MarginKpi;
use Modules\Analytics\Kpis\PayablesAgingKpi;
use Modules\Analytics\Kpis\ProjectMarginKpi;
use Modules\Analytics\Kpis\PurchasesKpi;
use Modules\Analytics\Kpis\ReceivablesAgingKpi;
use Modules\Analytics\Kpis\SalesKpi;
use Modules\Analytics\Kpis\StockValueKpi;
use Modules\Analytics\Support\ReportPeriod;

/**
 * The indicators of the reporting layer and how they are compared. The catalog owns the rules that make the figures
 * honest: an indicator the caller may not read is reported as unavailable instead of failing the whole report, a
 * snapshot indicator is never compared with a past period it cannot be rebuilt for, and a change against a missing or
 * zero base has no percentage.
 */
final readonly class KpiCatalog
{
    /**
     * Rows an export may carry per indicator.
     */
    public const int MaxExportRows = 5000;

    /**
     * @var list<class-string<KpiCalculator>>
     */
    private const array Calculators = [
        SalesKpi::class,
        PurchasesKpi::class,
        MarginKpi::class,
        ReceivablesAgingKpi::class,
        PayablesAgingKpi::class,
        StockValueKpi::class,
        ProjectMarginKpi::class,
        HeadcountKpi::class,
    ];

    /**
     * Inject the collaborators.
     */
    public function __construct(private ModuleAccessGate $gate, private TenantContext $tenantContext) {}

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'sales', 'purchases', 'margin', 'receivables-aging', 'payables-aging', 'stock-value', 'project-margin', 'hr-headcount',
        ];
    }

    /**
     * @return array<string, KpiCalculator>
     */
    public function calculators(): array
    {
        $all = [];

        foreach (self::Calculators as $class) {
            $calculator = app($class);
            $all[$calculator->key()] = $calculator;
        }

        return $all;
    }

    /**
     * The calculator of a key.
     *
     * @throws InvalidArgumentException for an unknown key
     */
    public function calculator(string $key): KpiCalculator
    {
        return $this->calculators()[$key] ?? throw new InvalidArgumentException("Unknown indicator [{$key}].");
    }

    /**
     * @param  list<string>  $keys  empty for every indicator
     * @return array{period: array{from: string, to: string}, comparison_period: ?array{from: string, to: string}, kpis: list<array<string, mixed>>}
     */
    public function report(array $keys, ReportPeriod $period, User $user): array
    {
        $calculators = $this->calculators();
        $keys = $keys === [] ? array_keys($calculators) : $keys;
        $comparison = $period->comparison();

        return [
            'period' => $period->toArray(),
            'comparison_period' => $comparison?->toArray(),
            'kpis' => array_map(fn (string $key): array => $this->entry($this->calculator($key), $period, $comparison, $user), $keys),
        ];
    }

    /**
     * Detail rows of an indicator for exports, capped at {@see MaxExportRows}.
     *
     * @return list<array<string, scalar|null>>
     */
    public function rows(string $key, ReportPeriod $period, User $user): array
    {
        $calculator = $this->calculator($key);

        return $this->canRead($calculator, $user) ? $calculator->rows($period, self::MaxExportRows) : [];
    }

    /**
     * Whether the caller can read the module the indicator is drawn from.
     */
    public function canRead(KpiCalculator $calculator, User $user): bool
    {
        return $this->gate->allows($user, $this->tenantContext->getTenantId(), $calculator->requiredModule(), 'read');
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(KpiCalculator $calculator, ReportPeriod $period, ?ReportPeriod $comparison, User $user): array
    {
        $base = ['key' => $calculator->key(), 'label' => $calculator->label(), 'unit' => $calculator->unit(), 'kind' => $calculator->kind()];

        if (! $this->canRead($calculator, $user)) {
            return [...$base, 'available' => false, 'reason' => 'forbidden', 'value' => null, 'details' => null, 'comparison' => null, 'change' => null, 'change_percent' => null];
        }

        $current = $calculator->calculate($period);
        $entry = [...$base, 'available' => true, 'reason' => null, ...$current, 'comparison' => null, 'change' => null, 'change_percent' => null];

        if ($comparison === null) {
            return $entry;
        }

        if ($calculator->kind() === 'snapshot') {
            return [...$entry, 'reason' => 'snapshot_not_comparable'];
        }

        $previous = $calculator->calculate($comparison);
        $entry['comparison'] = ['period' => $comparison->toArray(), ...$previous];

        if ($current['value'] !== null && $previous['value'] !== null) {
            $scale = $calculator->unit() === 'count' ? 0 : 2;
            $entry['change'] = bcsub($current['value'], $previous['value'], $scale);
            $base = ltrim($previous['value'], '-');
            $entry['change_percent'] = bccomp($base, '0', $scale) === 0
                ? null
                : bcround(bcdiv(bcmul($entry['change'], '100', 4), $base, 4), 2);
        }

        return $entry;
    }
}
