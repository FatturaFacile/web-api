<?php

declare(strict_types=1);

namespace Enterprise\Foundation\Concerns;

use Enterprise\FeatureFlags\Services\FeatureManager;

trait FeatureAware
{
    public function isFeatureEnabled(string $featureKey): bool
    {
        if (app()->bound(FeatureManager::class)) {
            return app(FeatureManager::class)->isEnabled($featureKey, $this);
        }

        return false;
    }
}
