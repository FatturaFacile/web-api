<?php

declare(strict_types=1);

namespace App\Services\Retail;

use App\Contracts\FiscalPrinterGateway;
use App\Models\PosSale;

/**
 * Offline stand-in for a real RT gateway: derives deterministic document identifiers and makes no network call.
 */
class LocalFiscalPrinterGateway implements FiscalPrinterGateway
{
    public function transmit(PosSale $sale): array
    {
        return [
            'fiscal_document_number' => 'DOC-'.preg_replace('/\D/', '', $sale->receipt_number),
            'xml_lot_id' => 'LOT-'.$sale->created_at->format('YmdHis'),
            'z_report_number' => 'Z-'.$sale->created_at->format('Ymd'),
        ];
    }
}
