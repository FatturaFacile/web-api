<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\PosSale;

interface FiscalPrinterGateway
{
    /**
     * Transmit a POS sale to the fiscal printer / RT service.
     *
     * @return array{fiscal_document_number: string, xml_lot_id: string, z_report_number: string}
     */
    public function transmit(PosSale $sale): array;
}
