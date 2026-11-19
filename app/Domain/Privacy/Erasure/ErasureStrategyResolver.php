<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Erasure;

use App\Domain\Privacy\Contracts\PrivacySelectiveErasureStrategyInterface;

final class ErasureStrategyResolver
{
    public function forCountry(?string $country): PrivacySelectiveErasureStrategyInterface
    {
        return match (strtoupper((string) $country)) {
            'CH' => new SwissNlpdErasureStrategy,
            'IT' => new ItalianGdprErasureStrategy,
            default => new DualCrossBorderErasureStrategy,
        };
    }
}
