<?php

declare(strict_types=1);

namespace Modules\Integrations\Services;

use InvalidArgumentException;
use Modules\Integrations\Contracts\PaymentLinkGateway;
use Modules\Integrations\Gateways\LocalPaymentLinkGateway;
use Modules\Integrations\Gateways\StripePaymentLinkGateway;

/**
 * The one set of online payment drivers. Every flow that takes payments online (invoice payment links, association
 * fees) resolves its driver here by provider name.
 */
final class PaymentGatewayResolver
{
    /** @var array<string, class-string<PaymentLinkGateway>> */
    private const array Drivers = [
        'local' => LocalPaymentLinkGateway::class,
        'stripe' => StripePaymentLinkGateway::class,
    ];

    /**
     * @return list<string>
     */
    public static function providers(): array
    {
        return array_keys(self::Drivers);
    }

    /**
     * @throws InvalidArgumentException for a provider without a driver
     */
    public function driver(string $provider): PaymentLinkGateway
    {
        $class = self::Drivers[$provider] ?? throw new InvalidArgumentException("Unknown payment provider [{$provider}].");

        return app($class);
    }
}
