<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Gateways;

use Modules\SwissEbill\Application\Ports\EbillGatewayInterface;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Models\EbillSetting;

/**
 * Picks the gateway of a company: the sandbox simulator, or the HTTP client for SIX Paynet and PostFinance.
 */
final class EbillGatewayFactory
{
    public function __construct(private readonly SandboxEbillGateway $sandbox, private readonly HttpEbillGateway $http) {}

    public function for(EbillSetting $setting): EbillGatewayInterface
    {
        if (! $setting->provider->isSandbox()) {
            return $this->http;
        }

        // Production never silently falls back to the simulator: a sandbox provider is refused there.
        if (app()->isProduction()) {
            throw new EbillException('sandbox_not_allowed', 'The sandbox simulator is not available in production.', 422);
        }

        return $this->sandbox;
    }
}
