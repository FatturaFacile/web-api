<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Providers;

use App\Models\User;
use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\OcrExtraction\Infrastructure\Providers\AzureDocumentIntelligenceAdapter;
use Modules\OcrExtraction\Infrastructure\Providers\GeminiVisionAdapter;
use Modules\OcrExtraction\Infrastructure\Providers\LayoutLmV3Adapter;
use Modules\OcrExtraction\Infrastructure\Providers\OllamaVisionAdapter;
use Modules\OcrExtraction\Infrastructure\Providers\OpenAiVisionAdapter;
use Modules\OcrExtraction\Infrastructure\Providers\TesseractOcrAdapter;
use Modules\OcrExtraction\Infrastructure\Services\EnqueueOnInboxUpload;
use Modules\OcrExtraction\Infrastructure\Services\InboxUploadContext;
use Modules\OcrExtraction\Infrastructure\Services\OcrProviderRegistry;

/**
 * Intelligent document and invoice OCR extraction (Mod 092): routes, the OCR engines behind OcrVisionProviderInterface, the
 * DMS upload hook of the AI Inbox folder and the private channel of the real-time inbox. Tag another engine with
 * `ocr.vision_providers` to plug it in.
 */
final class OcrExtractionServiceProvider extends ServiceProvider
{
    private const string ProviderTag = 'ocr.vision_providers';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/ocr_extraction.php', 'ocr_extraction');

        $this->app->singleton(InboxUploadContext::class);

        $this->app->tag([
            OllamaVisionAdapter::class,
            LayoutLmV3Adapter::class,
            TesseractOcrAdapter::class,
            GeminiVisionAdapter::class,
            OpenAiVisionAdapter::class,
            AzureDocumentIntelligenceAdapter::class,
        ], self::ProviderTag);

        $this->app->bind(OcrProviderRegistry::class, fn ($app): OcrProviderRegistry => new OcrProviderRegistry($app->tagged(self::ProviderTag), $app->make(TenantAiConsentInterface::class)));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        DmsDocumentVersion::created(static function (DmsDocumentVersion $version): void {
            app(EnqueueOnInboxUpload::class)->handle($version);
        });

        Broadcast::channel('tenant.{tenantId}.ocr-inbox', static function (User $user, string $tenantId): bool {
            $roles = (array) config('permissions.modules.ocr_inbox.read', []);
            $membership = $user->tenants()->where('tenants.id', $tenantId)->first();

            return $membership !== null && (in_array('*', $roles, true) || in_array($membership->pivot->role, $roles, true));
        });
    }
}
