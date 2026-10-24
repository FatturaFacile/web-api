<?php

declare(strict_types=1);

namespace App\Actions\Retail;

use App\Contracts\FiscalPrinterGateway;
use App\Models\PosSale;
use App\Models\RtFiscalReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncPosSaleToRt
{
    public function __construct(private readonly FiscalPrinterGateway $gateway) {}

    /**
     * Transmit a completed sale to the fiscal printer once and store the fiscal identifiers.
     */
    public function execute(PosSale $sale): RtFiscalReceipt
    {
        return DB::transaction(function () use ($sale): RtFiscalReceipt {
            $sale = PosSale::lockForUpdate()->findOrFail($sale->id);

            if ($sale->status === 'refunded') {
                throw ValidationException::withMessages(['sale' => __('pos.rt_refunded_sale')]);
            }

            if (RtFiscalReceipt::where('pos_sale_id', $sale->id)->exists()) {
                throw ValidationException::withMessages(['sale' => __('pos.rt_already_transmitted')]);
            }

            $result = $this->gateway->transmit($sale);

            return RtFiscalReceipt::create([
                'pos_sale_id' => $sale->id,
                'z_report_number' => $result['z_report_number'],
                'fiscal_document_number' => $result['fiscal_document_number'],
                'xml_lot_id' => $result['xml_lot_id'],
                'rt_status' => 'transmitted',
                'transmitted_at' => now(),
            ]);
        });
    }
}
