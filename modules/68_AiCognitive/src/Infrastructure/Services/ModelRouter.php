<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Exceptions\AiPolicyViolationException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;

/**
 * Chooses the providers that may process a request, in the order they should be tried (the rest is the fail-over list).
 *
 * - Data classification: a provider takes data up to the classification the tenant set for it. A hosted provider with the
 *   PII masking on sees the masked text, so it counts one level lower (CONFIDENTIAL_PII becomes INTERNAL).
 * - Order: the provider the caller asked for; for CONFIDENTIAL_PII the local ones first; light tasks (classification,
 *   tagging, coding, query translation) go to the local or cheapest model; reasoning tasks to a hosted frontier model when
 *   the tenant has one. The default provider and a short latency SLA break ties.
 */
final readonly class ModelRouter
{
    public function __construct(private TenantProviderResolver $resolver) {}

    /**
     * @return list<ResolvedProvider>
     *
     * @throws AiPolicyViolationException when no provider of the tenant may process the data
     */
    public function route(AiCompletionRequest $request): array
    {
        $allowed = array_values(array_filter(
            $this->resolver->candidates($request->tenantId),
            fn (ResolvedProvider $provider): bool => $this->effective($request->classification, $provider)->atMost(TenantProviderResolver::classificationLimit($provider->config, $provider->type)),
        ));

        if ($allowed === []) {
            throw AiPolicyViolationException::noProvider();
        }

        $light = $request->task->isLight();
        $pii = $request->classification === DataClassification::ConfidentialPii;

        usort($allowed, static function (ResolvedProvider $a, ResolvedProvider $b) use ($request, $light, $pii): int {
            $score = static function (ResolvedProvider $provider) use ($request, $light, $pii): int {
                $score = 0;
                $score += $request->preferredProvider === $provider->type->value ? 1000 : 0;
                $score += ($pii || $light) && $provider->type->isLocal() ? 100 : 0;
                $score += ! $light && ! $pii && ! $provider->type->isLocal() ? 100 : 0;
                $score += $provider->config?->is_default === true ? 10 : 0;
                $score += $provider->config?->latency_sla_ms !== null && $provider->config->latency_sla_ms <= 2000 ? 1 : 0;

                return $score;
            };

            return $score($b) <=> $score($a);
        });

        return $allowed;
    }

    private function effective(DataClassification $classification, ResolvedProvider $provider): DataClassification
    {
        if ($provider->piiMasking() && $classification === DataClassification::ConfidentialPii) {
            return DataClassification::Internal;
        }

        return $classification;
    }
}
