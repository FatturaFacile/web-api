<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Carbon\CarbonInterface;
use Modules\DebtCollection\Models\InterestRate;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The late-payment interest rate periods of a jurisdiction: the Swiss statutory 5% (Art. 104 CO) or the Italian ECB
 * reference rate plus 8 points (D.Lgs. 231/2002). The rows of the company replace the seeded global ones of the same
 * kind; without any row of its own the company reads the global defaults.
 */
final class InterestRateResolver
{
    public const string StatutoryCh = 'statutory_ch';

    public const string EcbIt = 'ecb_it';

    /** @var array<string, list<array{from: CarbonInterface, to: CarbonInterface|null, rate: string}>> */
    private array $cache = [];

    public function kindFor(string $jurisdiction): string
    {
        return $jurisdiction === 'CH' ? self::StatutoryCh : self::EcbIt;
    }

    /**
     * @return list<array{from: CarbonInterface, to: CarbonInterface|null, rate: string}>
     */
    public function periods(string $tenantId, string $jurisdiction): array
    {
        $key = $tenantId.'|'.$jurisdiction;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $rows = InterestRate::query()
            ->where('jurisdiction', $jurisdiction)
            ->where('kind', $this->kindFor($jurisdiction))
            ->orderBy('effective_from')
            ->get();

        $own = $rows->filter(static fn (InterestRate $row): bool => $row->tenant_id === $tenantId);
        $chosen = $own->isNotEmpty() ? $own : $rows->filter(static fn (InterestRate $row): bool => $row->tenant_id === null);

        $periods = [];

        foreach ($chosen->values() as $row) {
            $periods[] = [
                'from' => $row->effective_from,
                'to' => $row->effective_to,
                'rate' => Decimal::add((string) $row->base_rate_percent, (string) $row->markup_percent),
            ];
        }

        return $this->cache[$key] = $periods;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
