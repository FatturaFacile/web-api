<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\FiscalRetail\Models\CommercialDocument;
use Modules\FiscalRetail\Models\DailyCorrispettivo;
use Modules\FiscalRetail\Services\CorrispettiviTransmitter;

/**
 * Sends again, with their original idempotency key, the commercial documents and closed days of a company whose
 * transmission failed, was never attempted (the process stopped right after the commit) or was abandoned mid-call.
 */
final class RetryCorrispettiviTransmissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const int BatchSize = 200;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $tenantContext, CorrispettiviTransmitter $transmitter): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->is_maintenance) {
            return;
        }

        $tenantContext->runWithTenant($tenant, function () use ($transmitter): void {
            $this->retryable(CommercialDocument::query())
                ->orderBy('created_at')
                ->limit(self::BatchSize)
                ->get()
                ->each(static fn (CommercialDocument $document) => $transmitter->transmitDocument($document));

            $this->retryable(DailyCorrispettivo::query())
                ->orderBy('business_date')
                ->limit(self::BatchSize)
                ->get()
                ->groupBy(static fn (DailyCorrispettivo $row): string => $row->business_date->toDateString())
                ->each(static fn ($rows) => $transmitter->transmitDay(DailyCorrispettivo::query()->whereDate('business_date', $rows->first()->business_date)->get()));
        });
    }

    /**
     * Rows left `pending` for more than a minute (the request that created them sends them itself right after the
     * commit), `failed`, or claimed by a worker that stopped answering.
     *
     * @template TModel of CommercialDocument|DailyCorrispettivo
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function retryable(Builder $query): Builder
    {
        return $query->where(static fn (Builder $state) => $state
            ->where('gateway_status', CorrispettiviTransmitter::StatusFailed)
            ->orWhere(static fn (Builder $pending) => $pending
                ->where('gateway_status', CorrispettiviTransmitter::StatusPending)
                ->where('updated_at', '<', now()->subMinute()))
            ->orWhere(static fn (Builder $stale) => $stale
                ->where('gateway_status', CorrispettiviTransmitter::StatusTransmitting)
                ->where('updated_at', '<', now()->subMinutes(CorrispettiviTransmitter::StaleClaimMinutes))));
    }
}
