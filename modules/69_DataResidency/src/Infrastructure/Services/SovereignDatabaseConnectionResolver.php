<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Modules\DataResidency\Domain\Exceptions\CrossBorderResidencyViolationException;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Infrastructure\Models\SovereignRegionNode;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;

/**
 * Resolves the geo-sharded PostgreSQL connection of a tenant or legal entity (`pgsql_ch_zrh`, `pgsql_it_mil`...). A shard
 * that is not declared in config/database.php falls back to the default connection, which is SQLite in local runs and in
 * the test suite. Data never joins across shards: the only cross-shard read is the strictly numeric group rollup.
 */
final class SovereignDatabaseConnectionResolver
{
    public function __construct(private readonly ResidencyPolicyResolver $policies) {}

    /** `ch-zrh-01` => `pgsql_ch_zrh`, unless the node's endpoint names a connection explicitly. */
    public function connectionName(SovereignRegionNode $node): string
    {
        $declared = $node->endpoint()['connection'] ?? null;

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        $parts = explode('-', $node->node_code);

        return 'pgsql_'.$parts[0].'_'.($parts[1] ?? 'main');
    }

    public function connectionFor(string $tenantId, ?string $legalEntityId = null): ConnectionInterface
    {
        $policy = $this->policies->resolve($tenantId, $legalEntityId);

        return $policy === null ? DB::connection() : $this->connectionForPolicy($policy);
    }

    public function connectionForPolicy(TenantDataResidencyPolicy $policy): ConnectionInterface
    {
        $policy->assertNodeAllowedForJurisdiction($policy->primaryDbNode);
        $name = $this->connectionName($policy->primaryDbNode);

        return config('database.connections.'.$name) === null ? DB::connection() : DB::connection($name);
    }

    /**
     * Group consolidation (Mod 383): runs the aggregate on the shard of each legal entity and returns the numbers only.
     * A non-numeric value, a nested value or an entity whose policy forbids consolidation is refused, so no personal
     * data can leave its shard through the rollup.
     *
     * @param  list<string>  $legalEntityIds
     * @param  Closure(ConnectionInterface, string): array<string, mixed>  $aggregate  Anything but numbers is refused below
     * @return array<string, array<string, int|float>> entity id => metric => number
     */
    public function aggregatedRollup(string $tenantId, array $legalEntityIds, Closure $aggregate): array
    {
        $rollup = [];

        foreach ($legalEntityIds as $entityId) {
            $policy = $this->policies->resolve($tenantId, $entityId);

            if ($policy !== null && ! $policy->allow_aggregated_non_pii_consolidation) {
                throw new CrossBorderResidencyViolationException("Legal entity {$entityId} does not allow consolidation of its aggregates.");
            }

            $metrics = $aggregate($this->connectionFor($tenantId, $entityId), $entityId);

            $numbers = [];

            foreach ($metrics as $metric => $value) {
                if (! is_int($value) && ! is_float($value)) {
                    throw new DataResidencyException("The consolidation metric [{$metric}] is not a number: only non-personal aggregates may leave a shard.");
                }

                $numbers[$metric] = $value;
            }

            $rollup[$entityId] = $numbers;
        }

        return $rollup;
    }
}
