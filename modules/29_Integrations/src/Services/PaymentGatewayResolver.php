<?php

declare(strict_types=1);

namespace Modules\Integrations\Services;

use InvalidArgumentException;
use Modules\Integrations\Contracts\PaymentLinkGateway;
use Modules\Integrations\Exceptions\PaymentGatewayException;
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
        return array_values(array_filter(array_keys(self::Drivers), static fn (string $provider): bool => self::isAvailable($provider)));
    }

    /**
     * The local driver takes payments that a signed request merely declares, with no provider behind them: it exists
     * for development and tests, and is never available in production or staging.
     */
    public static function isAvailable(string $provider): bool
    {
        return isset(self::Drivers[$provider]) && ($provider !== 'local' || app()->environment(['local', 'testing']));
    }

    /**
     * @throws InvalidArgumentException for a provider without a driver
     * @throws PaymentGatewayException for the local driver outside development and tests
     */
    public function driver(string $provider): PaymentLinkGateway
    {
        $class = self::Drivers[$provider] ?? throw new InvalidArgumentException("Unknown payment provider [{$provider}].");

        if (! self::isAvailable($provider)) {
            throw new PaymentGatewayException('No online payment provider is configured for this company.');
        }

        return app($class);
    }
}
