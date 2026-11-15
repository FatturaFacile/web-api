<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Actions;

use App\Models\PosSession;
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

            $this->assertNoOpenSessions($day);

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

    /**
     * The takings of a session reach the commercial documents of its day: a day cannot be frozen while a till that
     * started on or before it is still open.
     */
    private function assertNoOpenSessions(CarbonImmutable $day): void
    {
        $open = PosSession::query()->where('status', 'open')->pluck('opened_at')
            ->filter(fn (mixed $openedAt): bool => $this->clock->businessDate($openedAt) <= $day->toDateString())
            ->count();

        if ($open > 0) {
            throw ValidationException::withMessages(['date' => trans_choice('sales.corrispettivi_open_sessions', $open, ['count' => $open])]);
        }
    }
}
