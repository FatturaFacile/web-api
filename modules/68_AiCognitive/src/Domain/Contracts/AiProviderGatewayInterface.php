<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Contracts;

use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionResult;

/**
 * The single door to every model: it applies the tenant policy (provider, data classification, PII masking), the quota, the
 * rate limit, the retries, the circuit breaker and the response cache, and it logs usage and cost. Tests bind a fake.
 */
interface AiProviderGatewayInterface
{
    /**
     * @throws AiCognitiveException
     */
    public function complete(AiCompletionRequest $request): AiCompletionResult;

    /**
     * @return list<float>
     *
     * @throws AiCognitiveException
     */
    public function embed(string $tenantId, string $text): array;
}
