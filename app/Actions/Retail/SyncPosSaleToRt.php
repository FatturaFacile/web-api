<?php

declare(strict_types=1);

namespace App\Actions\Retail;

use App\Contracts\FiscalPrinterGateway;
use App\Models\PosSale;
use App\Models\RtFiscalReceipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\FiscalRetail\Actions\IssueCommercialDocument;
use Throwable;

class SyncPosSaleToRt
{
    /**
     * A claim older than this belongs to a worker that stopped during the call and can be taken again.
     */
    private const int StaleClaimMinutes = 10;

    public function __construct(
        private readonly FiscalPrinterGateway $gateway,
        private readonly IssueCommercialDocument $commercialDocuments,
    ) {}

    /**
     * Transmit a completed sale to the fiscal printer once and store the fiscal identifiers. With a simulated printer
     * the receipt is `simulated` and has no transmission time: nothing reached the Agenzia delle Entrate.
     *
     * The receipt is first recorded as `pending` (one per sale, by a unique index) and committed; the printer is called
     * afterwards, without holding the lock of the sale. When the printer fails the receipt stays `error` and calling
     * this again sends it again. The sale is the idempotency key the printer receives on every attempt.
     */
    public function execute(PosSale $sale): RtFiscalReceipt
    {
        $receipt = DB::transaction(function () use ($sale): RtFiscalReceipt {
            $sale = PosSale::lockForUpdate()->findOrFail($sale->id);

            if ($sale->status === 'refunded') {
                throw ValidationException::withMessages(['sale' => __('pos.rt_refunded_sale')]);
            }

            $existing = RtFiscalReceipt::where('pos_sale_id', $sale->id)->first();

            if ($existing !== null) {
                if (in_array($existing->rt_status, [RtFiscalReceipt::StatusPending, RtFiscalReceipt::StatusError], true)) {
                    return $existing;
                }

                throw ValidationException::withMessages(['sale' => __('pos.rt_already_transmitted')]);
            }

            $this->commercialDocuments->fromPosSale($sale);

            return RtFiscalReceipt::create(['pos_sale_id' => $sale->id, 'rt_status' => RtFiscalReceipt::StatusPending]);
        });

        return $this->transmit($receipt);
    }

    private function transmit(RtFiscalReceipt $receipt): RtFiscalReceipt
    {
        $claimed = RtFiscalReceipt::whereKey($receipt->id)
            ->where(static fn (Builder $state) => $state
                ->whereIn('rt_status', [RtFiscalReceipt::StatusPending, RtFiscalReceipt::StatusError])
                ->orWhere(static fn (Builder $stale) => $stale
                    ->where('rt_status', RtFiscalReceipt::StatusTransmitting)
                    ->where('updated_at', '<', now()->subMinutes(self::StaleClaimMinutes))))
            ->update(['rt_status' => RtFiscalReceipt::StatusTransmitting]);

        if ($claimed === 0) {
            return $receipt->refresh();
        }

        $receipt->forceFill(['rt_status' => RtFiscalReceipt::StatusTransmitting])->syncOriginal();

        try {
            $result = $this->gateway->transmit(PosSale::findOrFail($receipt->pos_sale_id));
            $simulated = $this->gateway->isSimulated();

            $receipt->update([
                'z_report_number' => $result['z_report_number'],
                'fiscal_document_number' => $result['fiscal_document_number'],
                'xml_lot_id' => $result['xml_lot_id'],
                'rt_status' => $simulated ? RtFiscalReceipt::StatusSimulated : RtFiscalReceipt::StatusTransmitted,
                'transmitted_at' => $simulated ? null : now(),
                'transmission_error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);
            $receipt->update(['rt_status' => RtFiscalReceipt::StatusError, 'transmission_error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $receipt;
    }
}
