<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Contracts;

use App\Models\Tenant;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Models\CommercialDocument;

/**
 * A transmission channel of commercial documents and daily totals. Calls are made after the database transaction that
 * stored the data has committed, and may be repeated after a failure: the idempotency key is the same on every attempt,
 * so a channel that already accepted a transmission must answer with the same result instead of registering it twice.
 */
interface CorrispettiviGateway
{
    /**
     * Stable identifier stored on documents and daily rows.
     */
    public function name(): string;

    /**
     * Refuses at once when the channel cannot transmit at all (a driver that is not implemented or not configured), so
     * nothing is numbered or closed for a channel that would never accept it.
     *
     * @throws CorrispettiviGatewayException
     */
    public function assertAvailable(): void;

    /**
     * Hand a commercial document over to the channel.
     *
     * @return array{status: string, reference: string}
     *
     * @throws CorrispettiviGatewayException when the channel refuses or cannot process the document
     */
    public function sendDocument(Tenant $tenant, CommercialDocument $document, string $idempotencyKey): array;

    /**
     * Hand the closed totals of a day over to the channel.
     *
     * @param  list<array<string, mixed>>  $rows  one row per VAT rate or nature
     * @return array{status: string, reference: string}
     *
     * @throws CorrispettiviGatewayException when the channel refuses or cannot process the totals
     */
    public function sendDailyTotals(Tenant $tenant, string $businessDate, array $rows, string $idempotencyKey): array;
}
