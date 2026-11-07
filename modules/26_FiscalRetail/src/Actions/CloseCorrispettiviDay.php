<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Actions;

use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Models\DailyCorrispettivo;
use Modules\FiscalRetail\Services\CorrispettiviAggregator;
use Modules\FiscalRetail\Services\CorrispettiviDayLock;
use Modules\FiscalRetail\Services\CorrispettiviGatewayManager;
use Modules\FiscalRetail\Services\CorrispettiviTransmitter;

/**
 * Freezes the totals of a day, once. After the closure no document can be issued for that day.
 */
final readonly class CloseCorrispettiviDay
{
    public function __construct(
        private CorrispettiviAggregator $aggregator,
        private CorrispettiviGatewayManager $gateways,
        private TenantContext $tenantContext,
        private CorrispettiviDayLock $dayLock,
        private TenantClock $clock,
        private CorrispettiviTransmitter $transmitter,
    ) {}

    /**
     * A day is in the future when it has not started yet on the wall clock of the company, not in UTC.
     *
     * @return Collection<int, DailyCorrispettivo>
     */
    public function execute(CarbonImmutable $day): Collection
    {
        if ($day->toDateString() > $this->clock->today()->toDateString()) {
            throw ValidationException::withMessages(['date' => __('sales.corrispettivi_future_day')]);
        }

        $closed = DB::transaction(function () use ($day): Collection {
            $this->dayLock->acquire((string) $this->tenantContext->getTenantId(), $day->toDateString());

            if (DailyCorrispettivo::where('business_date', $day->toDateString())->exists()) {
                throw ValidationException::withMessages(['date' => __('sales.corrispettivi_day_closed')]);
            }

            $rows = $this->aggregator->between($day, $day);

            if ($rows === []) {
                throw ValidationException::withMessages(['date' => __('sales.corrispettivi_day_empty')]);
            }

            try {
                $gateway = $this->gateways->gateway();
                $gateway->assertAvailable();
            } catch (CorrispettiviGatewayException $e) {
                throw ValidationException::withMessages(['gateway' => $e->getMessage()]);
            }

            $transmissionKey = (string) Str::uuid();

            return collect($rows)->map(static fn (array $row): DailyCorrispettivo => DailyCorrispettivo::create([
                ...$row,
                'gateway' => $gateway->name(),
                'gateway_status' => CorrispettiviTransmitter::StatusPending,
                'transmission_key' => $transmissionKey,
                'closed_at' => now(),
            ]));
        });

        // The day is closed once committed. The totals are sent afterwards, without the day lock: a failure leaves the
        // rows `failed`, to be sent again with the same idempotency key.
        DB::afterCommit(fn () => $this->transmitter->transmitDay($closed));

        return $closed;
    }
}
