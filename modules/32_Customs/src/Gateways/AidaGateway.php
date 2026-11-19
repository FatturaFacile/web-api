<?php

declare(strict_types=1);

namespace Modules\Customs\Gateways;

/**
 * Italian AIDA (ADM) sandbox channel.
 */
final class AidaGateway extends SandboxCustomsGateway
{
    public function name(): string
    {
        return 'aida';
    }

    protected function mrnCountry(): string
    {
        return 'IT';
    }
}
