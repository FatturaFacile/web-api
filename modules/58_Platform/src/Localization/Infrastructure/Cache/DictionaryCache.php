<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Versioned cache of the merged dictionaries: i18n:{tenant}:{locale}:{version}. Driver agnostic (no Cache::tags): a change
 * bumps the version of its scope, so the next read misses and rebuilds while the stale entries simply expire.
 * The version of a tenant dictionary combines the system version and the tenant version: a system change reaches every
 * tenant, a tenant change only that tenant.
 */
final class DictionaryCache
{
    private const int TTL_SECONDS = 86400;

    private const string GLOBAL_SCOPE = 'global';

    public function version(?string $tenantId): string
    {
        $system = $this->counter(self::GLOBAL_SCOPE);

        return $tenantId === null ? (string) $system : $system.'.'.$this->counter($tenantId);
    }

    /**
     * @param  Closure(): array<string, string>  $build
     * @return array{version_hash: string, entries: array<string, string>}
     */
    public function remember(?string $tenantId, string $locale, Closure $build): array
    {
        $key = sprintf('i18n:%s:%s:%s', $tenantId ?? self::GLOBAL_SCOPE, $locale, $this->version($tenantId));

        /** @var array{version_hash: string, entries: array<string, string>} $payload */
        $payload = $this->store()->remember($key, self::TTL_SECONDS, static function () use ($build): array {
            $entries = $build();
            ksort($entries);

            return ['version_hash' => substr(hash('sha256', (string) json_encode($entries, JSON_UNESCAPED_UNICODE)), 0, 32), 'entries' => $entries];
        });

        return $payload;
    }

    public function flushTenant(string $tenantId): void
    {
        $this->bump($tenantId);
    }

    public function flushSystem(): void
    {
        $this->bump(self::GLOBAL_SCOPE);
    }

    private function counter(string $scope): int
    {
        return (int) $this->store()->get($this->counterKey($scope), 1);
    }

    private function bump(string $scope): void
    {
        $this->store()->forever($this->counterKey($scope), $this->counter($scope) + 1);
    }

    private function counterKey(string $scope): string
    {
        return 'i18n:version:'.$scope;
    }

    private function store(): Repository
    {
        return Cache::store();
    }
}
