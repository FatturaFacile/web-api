<?php

declare(strict_types=1);

namespace Enterprise\AiGateway\Providers;

use Enterprise\AiGateway\Contracts\DocumentExtractorInterface;
use Enterprise\AiGateway\Contracts\EnterpriseAiGatewayInterface;
use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Enterprise\AiGateway\Contracts\TextRecognizerInterface;
use Enterprise\AiGateway\Drivers\AzureOpenAiDriver;
use Enterprise\AiGateway\Drivers\ClaudeDriver;
use Enterprise\AiGateway\Drivers\GeminiDriver;
use Enterprise\AiGateway\Drivers\LocalFakeDriver;
use Enterprise\AiGateway\Drivers\MistralDriver;
use Enterprise\AiGateway\Drivers\OllamaDriver;
use Enterprise\AiGateway\Drivers\OpenAiDriver;
use Enterprise\AiGateway\Services\AiDriverRegistry;
use Enterprise\AiGateway\Services\DenyExternalAiConsent;
use Enterprise\AiGateway\Services\EnterpriseAiGateway;
use Enterprise\AiGateway\Services\GuardedDocumentExtractor;
use Enterprise\AiGateway\Services\PlainTextRecognizer;
use Illuminate\Support\ServiceProvider;

final class AiGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiDriverRegistry::class, function () {
            $registry = new AiDriverRegistry;

            $registry->register('local', new LocalFakeDriver);

            $registry->register('ollama', new OllamaDriver(
                host: (string) config('ai.drivers.ollama.base_url', 'http://localhost:11434'),
                defaultModel: (string) config('ai.drivers.ollama.model', 'llama3.2')
            ));

            $registry->register('openai', new OpenAiDriver(
                apiKey: config('ai.drivers.openai.api_key'),
                defaultModel: (string) config('ai.drivers.openai.model', 'gpt-4o-mini')
            ));

            $registry->register('gemini', new GeminiDriver(
                apiKey: config('ai.drivers.gemini.api_key'),
                defaultModel: (string) config('ai.drivers.gemini.model', 'gemini-1.5-flash')
            ));

            $registry->register('claude', new ClaudeDriver(
                apiKey: config('ai.drivers.claude.api_key'),
                defaultModel: (string) config('ai.drivers.claude.model', 'claude-3-5-sonnet-20241022')
            ));

            $registry->register('azure', new AzureOpenAiDriver(
                endpoint: config('ai.drivers.azure.endpoint'),
                apiKey: config('ai.drivers.azure.api_key'),
                deployment: config('ai.drivers.azure.deployment', 'gpt-4o')
            ));

            $registry->register('mistral', new MistralDriver(
                apiKey: config('ai.drivers.mistral.api_key'),
                defaultModel: (string) config('ai.drivers.mistral.model', 'mistral-small-latest')
            ));

            $registry->setDefaultDriver((string) config('ai.default_driver', 'ollama'));
            $registry->setFallbackDriver((string) config('ai.fallback_driver', 'ollama'));

            return $registry;
        });

        $this->app->singleton(EnterpriseAiGatewayInterface::class, EnterpriseAiGateway::class);
        $this->app->singleton(EnterpriseAiGateway::class);
        $this->app->singleton(DocumentExtractorInterface::class, GuardedDocumentExtractor::class);
        $this->app->singleton(TextRecognizerInterface::class, PlainTextRecognizer::class);
        $this->app->singletonIf(TenantAiConsentInterface::class, DenyExternalAiConsent::class);
    }
}
