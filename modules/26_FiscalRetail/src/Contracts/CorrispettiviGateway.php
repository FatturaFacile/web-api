<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Contracts;

use App\Models\Tenant;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Models\CommercialDocument;

interface CorrispettiviGateway
{
    /**
     * Stable identifier stored on documents and daily rows.
     */
    public function name(): string;

    /**
     * Hand a commercial document over to the channel.
     *
     * @return array{status: string, reference: string}
     *
     * @throws CorrispettiviGatewayException when the channel refuses or cannot process the document
     */
    public function sendDocument(Tenant $tenant, CommercialDocument $document): array;

    /**
     * Hand the closed totals of a day over to the channel.
     *
     * @param  list<array<string, mixed>>  $rows  one row per VAT rate or nature
     * @return array{status: string, reference: string}
     *
     * @throws CorrispettiviGatewayException when the channel refuses or cannot process the totals
     */
    public function sendDailyTotals(Tenant $tenant, string $businessDate, array $rows): array;
}
