<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\DataResidency\Domain\Exceptions\CrossBorderResidencyViolationException;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;

/**
 * Builds the object-storage disk (DMS, Mod 007) of a policy from its S3 node: the regional endpoint is pinned, the bucket
 * prefix is per tenant and legal entity, and any cross-region replication setting is refused.
 */
final class SovereignS3StorageRouter
{
    /** Disk options that would copy objects out of the region. */
    private const array REPLICATION_KEYS = ['replication', 'replicate_to', 'cross_region', 'cross_region_replication', 'mirror'];

    /**
     * @return array<string, mixed>
     */
    public function diskConfig(TenantDataResidencyPolicy $policy): array
    {
        $node = $policy->s3StorageNode;
        $policy->assertNodeAllowedForJurisdiction($node);
        $endpoint = $node->endpoint();

        $config = [
            'driver' => 's3',
            'key' => $endpoint['access_key'] ?? null,
            'secret' => $endpoint['secret_key'] ?? null,
            'region' => (string) ($endpoint['region'] ?? $node->canton_or_region),
            'bucket' => (string) ($endpoint['bucket'] ?? 'fatturafacile-'.$node->node_code),
            'endpoint' => $endpoint['endpoint'] ?? null,
            'use_path_style_endpoint' => true,
            'root' => 'tenants/'.$policy->tenant_id.'/'.($policy->legal_entity_id ?? 'default'),
            'visibility' => 'private',
            'throw' => true,
        ];

        $this->assertNoCrossRegionReplication($endpoint);

        return $config;
    }

    public function disk(TenantDataResidencyPolicy $policy): Filesystem
    {
        return Storage::build($this->diskConfig($policy));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function assertNoCrossRegionReplication(array $config): void
    {
        foreach (self::REPLICATION_KEYS as $key) {
            if (! empty($config[$key])) {
                throw new CrossBorderResidencyViolationException("Object storage option [{$key}] would replicate data outside the sovereign region.");
            }
        }
    }
}
