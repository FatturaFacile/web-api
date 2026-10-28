<?php

declare(strict_types=1);

namespace Enterprise\FeatureFlags\Contracts;

interface FeatureManagerInterface
{
    public function isEnabled(string $featureKey, mixed $scope = null): bool;

    public function enable(string $featureKey, mixed $scope = null): void;

    public function disable(string $featureKey, mixed $scope = null): void;
}
