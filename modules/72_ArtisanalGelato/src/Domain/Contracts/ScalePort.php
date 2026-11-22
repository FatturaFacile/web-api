<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Contracts;

/**
 * Connected scale (Bilancia Connessa). A hardware adapter (serial, USB, network) implements read(); the manual and simulated
 * adapter of this module is the default so the workflows run without a device.
 */
interface ScalePort
{
    /**
     * Current stable weight in grams, or null when the scale has no stable reading.
     */
    public function read(?string $device = null): ?float;

    public function source(): string;
}
