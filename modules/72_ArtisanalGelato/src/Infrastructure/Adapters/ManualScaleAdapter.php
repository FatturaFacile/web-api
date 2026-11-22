<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Infrastructure\Adapters;

use Modules\ArtisanalGelato\Domain\Contracts\ScalePort;

/**
 * Default scale: there is no device, the operator types the weight (see GelatoScaleController). read() returns null.
 */
final class ManualScaleAdapter implements ScalePort
{
    public function read(?string $device = null): ?float
    {
        return null;
    }

    public function source(): string
    {
        return 'manual';
    }
}
