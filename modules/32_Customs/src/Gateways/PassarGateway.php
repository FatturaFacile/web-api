<?php

declare(strict_types=1);

namespace Modules\Customs\Gateways;

/**
 * Swiss Passar (BAZG) sandbox channel.
 */
final class PassarGateway extends SandboxCustomsGateway
{
    public function name(): string
    {
        return 'passar';
    }

    protected function mrnCountry(): string
    {
        return 'CH';
    }
}
