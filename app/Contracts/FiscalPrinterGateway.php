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

    /**
     * True when the gateway only simulates the printer: nothing reaches the Agenzia delle Entrate.
     */
    public function isSimulated(): bool;
}
