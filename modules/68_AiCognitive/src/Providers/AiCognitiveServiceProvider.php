<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Providers;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Illuminate\Support\ServiceProvider;
use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Infrastructure\Adapters\AnthropicAdapter;
use Modules\AiCognitive\Infrastructure\Adapters\AzureOpenAiAdapter;
use Modules\AiCognitive\Infrastructure\Adapters\GeminiAdapter;
use Modules\AiCognitive\Infrastructure\Adapters\OllamaAdapter;
use Modules\AiCognitive\Infrastructure\Adapters\OpenAiAdapter;
use Modules\AiCognitive\Infrastructure\Executors\AcknowledgeExecutor;
use Modules\AiCognitive\Infrastructure\Executors\ErpDraftExecutor;
use Modules\AiCognitive\Infrastructure\Executors\ReconciliationExecutor;
use Modules\AiCognitive\Infrastructure\Services\AiAuditor;
use Modules\AiCognitive\Infrastructure\Services\AiProviderGateway;
use Modules\AiCognitive\Infrastructure\Services\AiUsageMeter;
use Modules\AiCognitive\Infrastructure\Services\AutomationPolicy;
use Modules\AiCognitive\Infrastructure\Services\CircuitBreaker;
use Modules\AiCognitive\Infrastructure\Services\PiiMaskingService;
use Modules\AiCognitive\Infrastructure\Services\SuggestionService;
use Modules\AiCognitive\Infrastructure\Services\TenantProviderResolver;

/**
 * AI Services & Cognitive Automation Layer (Mod 093): routes, the provider adapters behind AiAdapterInterface, the executors
 * of approved suggestions and the gateway binding. Tag another adapter with `ai_cognitive.adapters`, or another executor with
 * `ai_cognitive.executors`, to plug it in.
 */
final class AiCognitiveServiceProvider extends ServiceProvider
{
    private const string AdapterTag = 'ai_cognitive.adapters';

    private const string ExecutorTag = 'ai_cognitive.executors';

    public function register(): void
    {
        $this->app->singleton(PiiMaskingService::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(AiUsageMeter::class);
        $this->app->singleton(AiAuditor::class);
        $this->app->singleton(AutomationPolicy::class);

        $this->app->tag([OllamaAdapter::class, OpenAiAdapter::class, AnthropicAdapter::class, GeminiAdapter::class, AzureOpenAiAdapter::class], self::AdapterTag);
        $this->app->tag([ErpDraftExecutor::class, ReconciliationExecutor::class, AcknowledgeExecutor::class], self::ExecutorTag);

        $this->app->bind(TenantProviderResolver::class, fn ($app): TenantProviderResolver => new TenantProviderResolver($app->tagged(self::AdapterTag), $app->make(TenantAiConsentInterface::class)));
        $this->app->bind(SuggestionService::class, fn ($app): SuggestionService => new SuggestionService($app->tagged(self::ExecutorTag), $app->make(AutomationPolicy::class), $app->make(AiAuditor::class)));
        $this->app->bind(AiProviderGatewayInterface::class, AiProviderGateway::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
