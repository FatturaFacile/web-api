<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;
use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\AiTaskType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Domain\Exceptions\AiCognitiveException;
use Modules\AiCognitive\Domain\Exceptions\AiProviderUnavailableException;
use Modules\AiCognitive\Domain\Exceptions\AiQuotaExceededException;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionRequest;
use Modules\AiCognitive\Domain\ValueObjects\AiCompletionResult;
use Modules\DataResidency\Domain\Exceptions\CrossBorderTransferBlockedException;
use Modules\DataResidency\Infrastructure\Services\CrossBorderEgressGuard;

/**
 * The single door to every model. For each provider the router offers it applies, in this order: circuit breaker, token
 * budget and rate limit, PII masking (hosted providers only), response cache, the call with exponential backoff retries,
 * re-hydration of the masked answer, usage and cost log and the audit trail. A provider that fails hands over to the next
 * one the router allowed; when all fail the last error is thrown. The cache key is built from the normalised masked prompt
 * (case and whitespace folded), so a repeated question is answered without a model call.
 */
final readonly class AiProviderGateway implements AiProviderGatewayInterface
{
    public function __construct(
        private ModelRouter $router,
        private CircuitBreaker $breaker,
        private AiUsageMeter $meter,
        private PiiMaskingService $masking,
        private AiAuditor $auditor,
        private TenantProviderResolver $resolver,
    ) {}

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $failure = null;

        foreach ($this->router->route($request) as $provider) {
            $type = $provider->type->value;

            if ($this->breaker->isOpen($request->tenantId, $type)) {
                $failure = AiProviderUnavailableException::circuitOpen($type);
                $this->meter->record($request->tenantId, $request->userId, $type, null, $request->task, $request->classification, null, 'circuit_open');

                continue;
            }

            try {
                $this->meter->assertAllowed($provider, $request->tenantId);
            } catch (AiQuotaExceededException $exception) {
                $failure = $exception;
                $this->meter->record($request->tenantId, $request->userId, $type, null, $request->task, $request->classification, null, 'quota_exceeded', $exception->getMessage());

                continue;
            }

            try {
                $this->assertEgressAllowed($request->tenantId, $provider);
            } catch (CrossBorderTransferBlockedException $exception) {
                $failure = $exception;
                $this->meter->record($request->tenantId, $request->userId, $type, null, $request->task, $request->classification, null, 'egress_blocked', $exception->getMessage());

                continue;
            }

            $masked = $provider->piiMasking() ? $this->masking->mask($request->system."\n\u{0001}\n".$request->prompt) : ['text' => $request->system."\n\u{0001}\n".$request->prompt, 'map' => []];
            [$system, $prompt] = explode("\n\u{0001}\n", $masked['text'], 2);
            $light = $request->task->isLight();
            $model = $provider->settings->modelFor($light);
            $cacheKey = 'ai-cognitive:response:'.hash('sha256', $request->tenantId.'|'.$type.'|'.$model.'|'.($request->expectJson ? 'j' : 't').'|'.mb_strtolower(trim((string) preg_replace('/\s+/', ' ', trim($system).'|'.trim($prompt)))));

            if ($request->cacheable) {
                $cached = Cache::get($cacheKey);

                if (is_array($cached)) {
                    $result = new AiCompletionResult($this->masking->rehydrate((string) $cached['content'], $masked['map']), $type, (string) $cached['model'], 0, 0, 0, true, $provider->piiMasking());
                    $this->meter->record($request->tenantId, $request->userId, $type, $model, $request->task, $request->classification, $result, 'ok', null, $provider->piiMasking());
                    $this->audit($request, $result, $prompt);

                    return $result;
                }
            }

            try {
                $answer = $this->callWithRetries($provider, $system, $prompt, $request, $light);
            } catch (AiProviderUnavailableException $exception) {
                $failure = $exception;
                $this->breaker->recordFailure($request->tenantId, $type);
                $this->meter->record($request->tenantId, $request->userId, $type, $model, $request->task, $request->classification, null, 'error', $exception->getMessage(), $provider->piiMasking());

                continue;
            }

            $this->breaker->recordSuccess($request->tenantId, $type);

            if ($request->cacheable) {
                Cache::put($cacheKey, ['content' => $answer->content, 'model' => $answer->model], now()->addSeconds((int) config('ai_cognitive.resilience.cache_ttl_seconds', 3600)));
            }

            $result = $answer
                ->withContent($this->masking->rehydrate($answer->content, $masked['map']), false, $provider->piiMasking())
                ->withCost($this->meter->cost($type, $answer->promptTokens, $answer->completionTokens));

            $this->meter->record($request->tenantId, $request->userId, $type, $model, $request->task, $request->classification, $result, 'ok', null, $provider->piiMasking());
            $this->audit($request, $result, $prompt);

            return $result;
        }

        throw $failure ?? new AiCognitiveException('No AI provider answered.');
    }

    public function embed(string $tenantId, string $text): array
    {
        $failure = null;

        foreach ($this->router->route(new AiCompletionRequest($tenantId, AiTaskType::Tagging, '', '', DataClassification::Internal)) as $provider) {
            if ($this->breaker->isOpen($tenantId, $provider->type->value)) {
                continue;
            }

            try {
                $this->assertEgressAllowed($tenantId, $provider);
            } catch (CrossBorderTransferBlockedException $exception) {
                $failure = $exception;

                continue;
            }

            $input = $provider->piiMasking() ? $this->masking->mask($text)['text'] : $text;

            try {
                $vector = $provider->adapter->embed($provider->settings, $input);
                $this->breaker->recordSuccess($tenantId, $provider->type->value);

                return $vector;
            } catch (AiProviderUnavailableException $exception) {
                $failure = $exception;
                $this->breaker->recordFailure($tenantId, $provider->type->value);
            }
        }

        throw $failure ?? new AiCognitiveException('No AI provider can embed text.');
    }

    /**
     * The model that embedded for a tenant, for the index to store next to the vector.
     */
    public function embeddingModel(string $tenantId): string
    {
        $candidates = $this->resolver->candidates($tenantId);

        return $candidates === [] ? 'local' : $candidates[0]->type->value.':'.($candidates[0]->settings->embeddingModel ?? 'default');
    }

    /**
     * @throws AiProviderUnavailableException after the last attempt failed
     */
    private function callWithRetries(ResolvedProvider $provider, string $system, string $prompt, AiCompletionRequest $request, bool $light): AiCompletionResult
    {
        $attempts = max(1, (int) config('ai_cognitive.resilience.max_attempts', 3));
        $backoff = (int) config('ai_cognitive.resilience.backoff_ms', 200);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $provider->adapter->complete($provider->settings, $system, $prompt, $request->maxTokens, $request->temperature, $request->expectJson, $light);
            } catch (AiProviderUnavailableException $exception) {
                if ($attempt >= $attempts) {
                    throw $exception;
                }

                if ($backoff > 0) {
                    usleep($backoff * 1000 * (2 ** ($attempt - 1)));
                }
            }
        }
    }

    /**
     * Art. 16 nLPD / Art. 44 GDPR: a hosted provider is called only when the data residency policy of the tenant allows the
     * country of its endpoint. The local provider never leaves the installation.
     *
     * @throws CrossBorderTransferBlockedException
     */
    private function assertEgressAllowed(string $tenantId, ResolvedProvider $provider): void
    {
        if ($provider->type->isLocal()) {
            return;
        }

        $target = match ($provider->type) {
            AiProviderType::OpenAi => $provider->settings->endpoint ?: 'https://api.openai.com',
            AiProviderType::Anthropic => $provider->settings->endpoint ?: 'https://api.anthropic.com',
            AiProviderType::Gemini => $provider->settings->endpoint ?: 'https://generativelanguage.googleapis.com',
            default => (string) $provider->settings->endpoint,
        };

        app(CrossBorderEgressGuard::class)->assertAllowed($tenantId, null, 'ai', $target);
    }

    private function audit(AiCompletionRequest $request, AiCompletionResult $result, string $prompt): void
    {
        $this->auditor->record(
            $request->tenantId,
            'inference',
            $request->userId,
            null,
            null,
            $result->provider,
            $result->model,
            null,
            [
                'task' => $request->task->value,
                'classification' => $request->classification->value,
                'prompt_sha256' => hash('sha256', $prompt),
                'answer_sha256' => hash('sha256', $result->content),
                'tokens' => $result->totalTokens,
                'cache_hit' => $result->cacheHit,
                'pii_masked' => $result->piiMasked,
            ],
        );
    }
}
