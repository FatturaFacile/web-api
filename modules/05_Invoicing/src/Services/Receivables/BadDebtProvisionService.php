<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableProvisionRate;

/**
 * Prudential provision for doubtful receivables (accantonamento al fondo svalutazione crediti): the open amount of
 * every aging bucket times the provision rate of the bucket. Rates are configurable per company and bucket; without
 * a configured rate the prudential defaults apply (0.5% not due, 1% up to 30 days, 5% up to 60, 10% up to 90, 25%
 * up to 120, 50% beyond). The net realisable value is the open amount less the provision.
 */
final class BadDebtProvisionService
{
    public function __construct(private readonly ReceivableAgingService $aging) {}

    /**
     * @return array{as_of: string, currency: string, lines: list<array{key: string, from: int, to: int|null, open_amount: string, rate_percent: string, provision: string, is_default_rate: bool}>, total_open: string, total_provision: string, net_realizable_value: string}
     */
    public function calculate(string $tenantId, CarbonInterface $today, ?string $customerId = null, ?string $currency = null): array
    {
        $aging = $this->aging->aging($tenantId, $today, $customerId, $currency);
        $configured = $this->rates($tenantId);
        $lines = [];
        $total = '0';

        foreach ($aging['buckets'] as $bucket) {
            $isDefault = ! isset($configured[$bucket['key']]);
            $rate = $configured[$bucket['key']] ?? $this->defaultRate($bucket['from']);
            $open = $aging['totals'][$bucket['key']];
            $provision = Decimal::round2(Decimal::div(Decimal::mul($open, $rate), '100'));
            $total = Decimal::add($total, $provision);
            $lines[] = $bucket + ['open_amount' => $open, 'rate_percent' => Decimal::round2($rate), 'provision' => $provision, 'is_default_rate' => $isDefault];
        }

        return [
            'as_of' => $aging['as_of'],
            'currency' => $aging['currency'],
            'lines' => $lines,
            'total_open' => $aging['total_open'],
            'total_provision' => Decimal::round2($total),
            'net_realizable_value' => Decimal::round2(Decimal::sub($aging['total_open'], $total)),
        ];
    }

    /**
     * Replaces the configured rates of the company.
     *
     * @param  array<string, string|int|float>  $rates  rate percent by bucket key
     * @return array<string, string>
     */
    public function saveRates(string $tenantId, array $rates): array
    {
        DB::transaction(function () use ($tenantId, $rates): void {
            foreach ($rates as $key => $rate) {
                ReceivableProvisionRate::withTrashed()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'bucket_key' => (string) $key],
                    ['rate_percent' => Decimal::round2((string) $rate), 'deleted_at' => null],
                );
            }
        });

        return $this->rates($tenantId);
    }

    /**
     * @return array<string, string>
     */
    public function rates(string $tenantId): array
    {
        return ReceivableProvisionRate::query()->where('tenant_id', $tenantId)->pluck('rate_percent', 'bucket_key')->map(static fn ($rate): string => (string) $rate)->all();
    }

    private function defaultRate(int $fromDay): string
    {
        return match (true) {
            $fromDay === 0 => '0.50',
            $fromDay <= 1 => '1.00',
            $fromDay <= 31 => '5.00',
            $fromDay <= 61 => '10.00',
            $fromDay <= 91 => '25.00',
            default => '50.00',
        };
    }
}
