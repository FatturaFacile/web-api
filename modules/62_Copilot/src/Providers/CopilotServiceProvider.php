<?php

declare(strict_types=1);

namespace Modules\Copilot\Providers;

use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Support\ServiceProvider;
use Modules\Copilot\Embeddings\EmbeddingProviderManager;
use Modules\Copilot\Llm\LlmProviderManager;
use Modules\Copilot\Services\CopilotToolRegistry;
use Modules\Copilot\Tools\CatalogueQueryTool;
use Modules\Copilot\Tools\CreateInvoiceDraftTool;
use Modules\Copilot\Tools\CreateSalesQuoteTool;
use Modules\Copilot\Tools\KnowledgeSearchTool;
use Modules\Copilot\Tools\RequestEmployeeLeaveTool;
use Modules\Copilot\Tools\SemanticQueryTool;

/**
 * Registers the routes and bindings of the Copilot module.
 */
final class CopilotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmProviderManager::class);
        $this->app->singleton(EmbeddingProviderManager::class);

        $this->app->singleton(CopilotToolRegistry::class, fn ($app): CopilotToolRegistry => new CopilotToolRegistry(
            $app->make(ModuleAccessGate::class),
            [
                $app->make(SemanticQueryTool::class),
                $app->make(CatalogueQueryTool::class),
                $app->make(KnowledgeSearchTool::class),
                $app->make(CreateSalesQuoteTool::class),
                $app->make(CreateInvoiceDraftTool::class),
                $app->make(RequestEmployeeLeaveTool::class),
            ],
        ));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}
