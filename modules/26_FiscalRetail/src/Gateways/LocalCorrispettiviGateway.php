<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Gateways;

use App\Models\Tenant;
use Modules\FiscalRetail\Contracts\CorrispettiviGateway;
use Modules\FiscalRetail\Models\CommercialDocument;

/**
 * Records without transmitting: nothing leaves the application, so the status is `simulated`, never `transmitted`.
 */
final class LocalCorrispettiviGateway implements CorrispettiviGateway
{
    public function name(): string
    {
        return 'local';
    }

    public function sendDocument(Tenant $tenant, CommercialDocument $document): array
    {
        return ['status' => 'simulated', 'reference' => 'LOCAL-'.$document->id];
    }

    public function sendDailyTotals(Tenant $tenant, string $businessDate, array $rows): array
    {
        return ['status' => 'simulated', 'reference' => 'LOCAL-'.$businessDate];
    }
}
