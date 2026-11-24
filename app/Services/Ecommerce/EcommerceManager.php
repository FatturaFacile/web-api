<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Contracts\Ecommerce\EcommerceDriverInterface;
use App\Exceptions\EcommerceException;
use App\Models\EcommerceChannel;
use App\Services\Ecommerce\Drivers\PrestaShopDriver;
use App\Services\Ecommerce\Drivers\ShopifyDriver;
use App\Services\Ecommerce\Drivers\WooCommerceDriver;
use Closure;

/**
 * Resolves the driver of a channel from `channel.driver`. Custom drivers can be registered with {@see extend()}.
 */
class EcommerceManager
{
    /**
     * @var array<string, class-string<EcommerceDriverInterface>>
     */
    private const BUILT_IN = [
        'woocommerce' => WooCommerceDriver::class,
        'shopify' => ShopifyDriver::class,
        'prestashop' => PrestaShopDriver::class,
    ];

    /**
     * @var array<string, Closure(EcommerceChannel): EcommerceDriverInterface>
     */
    private array $custom = [];

    /**
     * @throws EcommerceException when no driver is registered for the channel
     */
    public function driver(EcommerceChannel $channel): EcommerceDriverInterface
    {
        if (isset($this->custom[$channel->driver])) {
            return ($this->custom[$channel->driver])($channel);
        }

        $class = self::BUILT_IN[$channel->driver] ?? throw EcommerceException::because(EcommerceException::UNSUPPORTED_DRIVER, ['driver' => $channel->driver]);

        return new $class($channel);
    }

    /**
     * Register or replace a driver.
     *
     * @param  Closure(EcommerceChannel): EcommerceDriverInterface  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->custom[$name] = $factory;
    }

    /**
     * Names of every available driver.
     *
     * @return array<int, string>
     */
    public function drivers(): array
    {
        return array_values(array_unique([...array_keys(self::BUILT_IN), ...array_keys($this->custom)]));
    }
}
