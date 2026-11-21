<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

use Modules\DataResidency\Domain\Exceptions\AiResidencyBoundaryException;
use Modules\DataResidency\Infrastructure\Models\SovereignRegionNode;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;

/**
 * Keeps AI/OCR inference (Mod 301) and vector search (Mod 302) on the nodes bound to the policy: an inference endpoint that
 * is not the bound node (an external, non-sovereign LLM) raises AiResidencyBoundaryException.
 */
final class SovereignAiAndVectorRouter
{
    public function __construct(private readonly SovereignDatabaseConnectionResolver $connections) {}

    public function inferenceNode(TenantDataResidencyPolicy $policy): SovereignRegionNode
    {
        $policy->assertNodeAllowedForJurisdiction($policy->aiInferenceNode);

        return $policy->aiInferenceNode;
    }

    /** The URL AI requests of the tenant must use. */
    public function inferenceEndpoint(TenantDataResidencyPolicy $policy): string
    {
        $url = $this->inferenceNode($policy)->endpoint()['endpoint'] ?? null;

        return is_string($url) ? $url : throw new AiResidencyBoundaryException('The bound AI inference node has no endpoint.');
    }

    /**
     * @throws AiResidencyBoundaryException
     */
    public function assertEndpointIsSovereign(TenantDataResidencyPolicy $policy, string $configuredUrl): void
    {
        $bound = parse_url($this->inferenceEndpoint($policy), PHP_URL_HOST);
        $target = parse_url($configuredUrl, PHP_URL_HOST);

        if (! is_string($bound) || ! is_string($target) || strcasecmp($bound, $target) !== 0) {
            throw new AiResidencyBoundaryException(sprintf('AI endpoint %s is not the sovereign node %s bound to the %s policy.', (string) $target, $policy->aiInferenceNode->node_code, $policy->jurisdiction_zone->value));
        }
    }

    /** The shard connection of the pgvector node. */
    public function vectorConnectionName(TenantDataResidencyPolicy $policy): string
    {
        $policy->assertNodeAllowedForJurisdiction($policy->vectorDbNode);

        return $this->connections->connectionName($policy->vectorDbNode);
    }
}
