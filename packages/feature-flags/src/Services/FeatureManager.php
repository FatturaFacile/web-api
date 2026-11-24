<?php

declare(strict_types=1);

namespace Enterprise\FeatureFlags\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\FeatureFlags\Contracts\FeatureManagerInterface;
use Enterprise\FeatureFlags\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class FeatureManager implements FeatureManagerInterface
{
    /**
     * Runtime in-memory overrides (Top priority, e.g. for testing or per-request toggles).
     *
     * @var array<string, bool>
     */
    private array $runtimeOverrides = [];

    public function isEnabled(string $featureKey, mixed $scope = null): bool
    {
        // Level 1: Runtime in-memory override
        $runtimeKey = $this->buildScopeKey($featureKey, $scope);
        if (isset($this->runtimeOverrides[$runtimeKey])) {
            return $this->runtimeOverrides[$runtimeKey];
        }
        if (isset($this->runtimeOverrides[$featureKey])) {
            return $this->runtimeOverrides[$featureKey];
        }

        [$tenantId, $userId] = $this->resolveScopeIds($scope);

        // Cache resolution key
        $cacheKey = "feature_flag:{$featureKey}:t_{$tenantId}:u_{$userId}";

        return (bool) Cache::remember($cacheKey, 300, function () use ($featureKey, $tenantId, $userId): bool {
            // Level 2: User-specific database override
            if ($userId !== null) {
                $userFlag = FeatureFlag::where('feature_key', $featureKey)
                    ->where('user_id', $userId)
                    ->first();
                if ($userFlag !== null) {
                    return $userFlag->is_enabled;
                }
            }

            // Level 3: Tenant-specific database override
            if ($tenantId !== null) {
                $tenantFlag = FeatureFlag::where('feature_key', $featureKey)
                    ->where('tenant_id', $tenantId)
                    ->first();
                if ($tenantFlag !== null) {
                    return $tenantFlag->is_enabled;
                }
            }

            // Level 4: Global database feature flag
            $globalFlag = FeatureFlag::where('feature_key', $featureKey)
                ->whereNull('tenant_id')
                ->whereNull('user_id')
                ->first();
            if ($globalFlag !== null) {
                return $globalFlag->is_enabled;
            }

            // Level 5: Application configuration default
            return (bool) config("features.{$featureKey}", false);
        });
    }

    public function enable(string $featureKey, mixed $scope = null): void
    {
        [$tenantId, $userId] = $this->resolveScopeIds($scope);

        if ($scope === 'runtime') {
            $this->runtimeOverrides[$featureKey] = true;

            return;
        }

        FeatureFlag::updateOrCreate(
            [
                'feature_key' => $featureKey,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ],
            [
                'id' => (string) Str::uuid7(),
                'is_enabled' => true,
            ]
        );

        $this->invalidateCache($featureKey, $tenantId, $userId);
    }

    public function disable(string $featureKey, mixed $scope = null): void
    {
        [$tenantId, $userId] = $this->resolveScopeIds($scope);

        if ($scope === 'runtime') {
            $this->runtimeOverrides[$featureKey] = false;

            return;
        }

        FeatureFlag::updateOrCreate(
            [
                'feature_key' => $featureKey,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ],
            [
                'id' => (string) Str::uuid7(),
                'is_enabled' => false,
            ]
        );

        $this->invalidateCache($featureKey, $tenantId, $userId);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveScopeIds(mixed $scope): array
    {
        $tenantId = null;
        $userId = null;

        if ($scope instanceof Tenant) {
            $tenantId = (string) $scope->getKey();
        } elseif ($scope instanceof User) {
            $userId = (string) $scope->getKey();
            $tenantId = $scope->tenant_id ? (string) $scope->tenant_id : null;
        } elseif (is_array($scope)) {
            $tenantId = isset($scope['tenant_id']) ? (string) $scope['tenant_id'] : null;
            $userId = isset($scope['user_id']) ? (string) $scope['user_id'] : null;
        } elseif (is_string($scope) && Str::isUuid($scope)) {
            $tenantId = $scope;
        }

        // Fallback to active TenantContext if tenantId is not explicitly set in scope
        if ($tenantId === null && app()->bound(TenantContext::class)) {
            $tenantContext = app(TenantContext::class);
            if ($tenantContext->hasTenant()) {
                $tenantId = (string) $tenantContext->getTenantId();
            }
        }

        return [$tenantId, $userId];
    }

    private function buildScopeKey(string $featureKey, mixed $scope): string
    {
        [$tenantId, $userId] = $this->resolveScopeIds($scope);

        return "{$featureKey}:{$tenantId}:{$userId}";
    }

    private function invalidateCache(string $featureKey, ?string $tenantId, ?string $userId): void
    {
        $cacheKey = "feature_flag:{$featureKey}:t_{$tenantId}:u_{$userId}";
        Cache::forget($cacheKey);
        unset($this->runtimeOverrides[$featureKey]);
    }
}
