<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Contracts\ShippingCarrierDriverInterface;
use Modules\Logistics\Support\ManualCarrierDriver;

/**
 * Registry of carrier drivers. Integrations (Issue #158) register themselves with `extend()` from their own service
 * provider; the first driver that supports a delivery note wins, the manual driver is the fallback.
 */
final class ShippingCarrierManager
{
    /** @var list<ShippingCarrierDriverInterface> */
    private array $drivers = [];

    private readonly ShippingCarrierDriverInterface $fallback;

    public function __construct(?ShippingCarrierDriverInterface $fallback = null)
    {
        $this->fallback = $fallback ?? new ManualCarrierDriver;
    }

    public function extend(ShippingCarrierDriverInterface $driver): void
    {
        array_unshift($this->drivers, $driver);
    }

    public function driverFor(SalesDeliveryNote $note): ShippingCarrierDriverInterface
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($note)) {
                return $driver;
            }
        }

        return $this->fallback;
    }
}
