<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Models\CommercialDocument;
use Modules\FiscalRetail\Models\DailyCorrispettivo;
use Throwable;

/**
 * Hands committed documents and daily totals to the gateway, outside any database transaction, so a slow or failing
 * channel never holds the locks of the numbering or of the day.
 *
 * A transmission first claims its rows with one conditional UPDATE (`pending` or `failed` to `transmitting`), so two
 * workers never send the same data at the same time; no lock is held during the call. The result, or the error, is
 * then written back: a `failed` row is retried later with the same idempotency key, and a claim older than
 * {@see self::StaleClaimMinutes} minutes (a worker that died during the call) can be taken again.
 */
final readonly class CorrispettiviTransmitter
{
    public const string StatusPending = 'pending';

    public const string StatusTransmitting = 'transmitting';

    public const string StatusFailed = 'failed';

    public const int StaleClaimMinutes = 10;

    public function __construct(private CorrispettiviGatewayManager $gateways) {}

    /**
     * Sends one document, unless another worker is already sending it or it was already sent.
     */
    public function transmitDocument(CommercialDocument $document): void
    {
        if (! $this->claim(CommercialDocument::query()->whereKey($document->id))) {
            return;
        }

        try {
            $gateway = $this->gateways->gateway();
            $result = $gateway->sendDocument($this->tenantOf($document), $document, (string) ($document->transmission_key ?? $document->id));
            $values = ['gateway' => $gateway->name(), 'gateway_status' => $result['status'], 'gateway_reference' => $result['reference'], 'gateway_error' => null];
        } catch (Throwable $e) {
            $values = ['gateway_status' => self::StatusFailed, 'gateway_error' => $this->errorOf($e)];
        }

        $this->write($document, CommercialDocument::query()->whereKey($document->id), $values);
    }

    /**
     * Sends the closed totals of one day: the rows of a day travel together, under one idempotency key.
     *
     * @param  Collection<int, DailyCorrispettivo>  $rows  every row of one business day
     */
    public function transmitDay(Collection $rows): void
    {
        $first = $rows->first();

        if ($first === null) {
            return;
        }

        $businessDate = $first->business_date->toDateString();
        $ids = $rows->map(static fn (DailyCorrispettivo $row): string => (string) $row->getKey())->all();
        $dayRows = static fn (): Builder => DailyCorrispettivo::query()->whereKey($ids);

        if (! $this->claim($dayRows())) {
            return;
        }

        try {
            $gateway = $this->gateways->gateway();
            $result = $gateway->sendDailyTotals(
                $this->tenantOf($first),
                $businessDate,
                $rows->map(static fn (DailyCorrispettivo $row): array => [
                    'business_date' => $businessDate,
                    ...$row->only(['tax_rate', 'vat_nature', 'sales_net', 'sales_tax', 'sales_gross', 'voided_gross', 'returned_gross', 'total_net', 'total_tax', 'total_gross', 'document_count']),
                ])->values()->all(),
                (string) ($first->transmission_key ?? "{$first->tenant_id}:{$businessDate}"),
            );
            $values = ['gateway' => $gateway->name(), 'gateway_status' => $result['status'], 'gateway_reference' => $result['reference'], 'gateway_error' => null];
        } catch (Throwable $e) {
            $values = ['gateway_status' => self::StatusFailed, 'gateway_error' => $this->errorOf($e)];
        }

        $dayRows()->update($values);
        $rows->each(static fn (DailyCorrispettivo $row) => $row->forceFill($values)->syncOriginal());
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function claim(Builder $query): bool
    {
        return $query
            ->where(static fn (Builder $state) => $state
                ->whereIn('gateway_status', [self::StatusPending, self::StatusFailed])
                ->orWhere(static fn (Builder $stale) => $stale
                    ->where('gateway_status', self::StatusTransmitting)
                    ->where('updated_at', '<', now()->subMinutes(self::StaleClaimMinutes))))
            ->update(['gateway_status' => self::StatusTransmitting]) > 0;
    }

    /**
     * @param  Builder<CommercialDocument>  $query
     * @param  array<string, mixed>  $values
     */
    private function write(CommercialDocument $document, Builder $query, array $values): void
    {
        $query->update($values);
        $document->forceFill($values)->syncOriginal();
    }

    private function tenantOf(CommercialDocument|DailyCorrispettivo $model): Tenant
    {
        return Tenant::query()->findOrFail($model->tenant_id);
    }

    private function errorOf(Throwable $e): string
    {
        if (! $e instanceof CorrispettiviGatewayException) {
            report($e);
        }

        return mb_substr($e->getMessage(), 0, 500);
    }
}
