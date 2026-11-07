<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use Modules\FiscalRetail\Contracts\CorrispettiviGateway;
use Modules\FiscalRetail\Exceptions\CorrispettiviGatewayException;
use Modules\FiscalRetail\Gateways\AdeCorrispettiviGateway;
use Modules\FiscalRetail\Gateways\LocalCorrispettiviGateway;

/**
 * Finds the transmission driver of the installation.
 */
final class CorrispettiviGatewayManager
{
    /**
     * @throws CorrispettiviGatewayException when the configured driver does not exist
     */
    public function gateway(): CorrispettiviGateway
    {
        $driver = (string) config('services.corrispettivi.driver', 'local');

        return match ($driver) {
            'local' => app(LocalCorrispettiviGateway::class),
            'ade' => app(AdeCorrispettiviGateway::class),
            default => throw new CorrispettiviGatewayException("Unknown corrispettivi driver [{$driver}]."),
        };
    }
}
