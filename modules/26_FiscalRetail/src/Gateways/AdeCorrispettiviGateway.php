<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Gateways;

use App\Models\Tenant;
use Modules\FiscalRetail\Contracts\CorrispettiviGateway;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Models\CommercialDocument;

/**
 * Placeholder of the transmission to the Agenzia delle Entrate. There is no agreement with the Agenzia yet and the
 * record layout of the transmission has not been implemented, so this driver refuses every call instead of pretending.
 * A real implementation needs the agreement, the approved solution and the current official specifications.
 */
final class AdeCorrispettiviGateway implements CorrispettiviGateway
{
    public function name(): string
    {
        return 'ade';
    }

    public function assertAvailable(): void
    {
        throw new CorrispettiviGatewayException(__('sales.corrispettivi_ade_unavailable'));
    }

    public function sendDocument(Tenant $tenant, CommercialDocument $document, string $idempotencyKey): array
    {
        throw new CorrispettiviGatewayException(__('sales.corrispettivi_ade_unavailable'));
    }

    public function sendDailyTotals(Tenant $tenant, string $businessDate, array $rows, string $idempotencyKey): array
    {
        throw new CorrispettiviGatewayException(__('sales.corrispettivi_ade_unavailable'));
    }
}
