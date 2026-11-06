<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Actions;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Models\DailyCorrispettivo;
use Modules\FiscalRetail\Services\CorrispettiviAggregator;
use Modules\FiscalRetail\Services\CorrispettiviGatewayManager;

/**
 * Freezes the totals of a day, once. After the closure no document can be issued for that day.
 */
final readonly class CloseCorrispettiviDay
{
    public function __construct(
        private CorrispettiviAggregator $aggregator,
        private CorrispettiviGatewayManager $gateways,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return Collection<int, DailyCorrispettivo>
     */
    public function execute(CarbonImmutable $day): Collection
    {
        if ($day->isFuture()) {
            throw ValidationException::withMessages(['date' => __('sales.corrispettivi_future_day')]);
        }

        return DB::transaction(function () use ($day): Collection {
            if (DailyCorrispettivo::where('business_date', $day->toDateString())->exists()) {
                throw ValidationException::withMessages(['date' => __('sales.corrispettivi_day_closed')]);
            }

            $rows = $this->aggregator->between($day, $day);

            if ($rows === []) {
                throw ValidationException::withMessages(['date' => __('sales.corrispettivi_day_empty')]);
            }

            $gateway = $this->gateways->gateway();

            try {
                $result = $gateway->sendDailyTotals($this->tenantContext->getTenant(), $day->toDateString(), $rows);
            } catch (CorrispettiviGatewayException $e) {
                throw ValidationException::withMessages(['gateway' => $e->getMessage()]);
            }

            return collect($rows)->map(static fn (array $row): DailyCorrispettivo => DailyCorrispettivo::create([
                ...$row,
                'gateway' => $gateway->name(),
                'gateway_status' => $result['status'],
                'gateway_reference' => $result['reference'],
                'closed_at' => now(),
            ]));
        });
    }
}
