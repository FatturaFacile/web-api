<?php

declare(strict_types=1);

namespace Modules\DataResidency\Providers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Modules\DataResidency\Domain\Events\ByokKillSwitchActivatedEvent;
use Modules\DataResidency\Domain\Events\UnauthorizedCrossBorderTransferBlockedEvent;
use Modules\DataResidency\Infrastructure\Crypto\ShortLivedDekMemoryCache;
use Modules\DataResidency\Infrastructure\Kms\KmsProviderRegistry;
use Modules\DataResidency\Infrastructure\Services\CrossBorderEgressGuard;
use Modules\DataResidency\Infrastructure\Services\ResidencyRuntime;
use Psr\Http\Message\RequestInterface;

/**
 * Data residency, sovereignty and BYOK/HYOK encryption (Mod 381): routes, the process-wide services (KMS adapters, DEK
 * cache, request residency context), the alert logging and the `residencyGuarded()` macro that puts the egress firewall in
 * front of an outbound HTTP call: `Http::residencyGuarded('webhook')->post($url, $payload)`.
 */
final class DataResidencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/data_residency.php', 'data_residency');
        $this->app->singleton(KmsProviderRegistry::class);
        $this->app->singleton(ShortLivedDekMemoryCache::class);
        $this->app->scoped(ResidencyRuntime::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Event::listen(UnauthorizedCrossBorderTransferBlockedEvent::class, static function (UnauthorizedCrossBorderTransferBlockedEvent $event): void {
            Log::warning('Unauthorized cross-border transfer blocked.', ['tenant_id' => $event->tenantId, 'country' => $event->destinationCountryIso2, 'purpose' => $event->purpose]);
        });

        Event::listen(ByokKillSwitchActivatedEvent::class, static function (ByokKillSwitchActivatedEvent $event): void {
            Log::critical('BYOK kill-switch activated.', ['tenant_id' => $event->tenantId, 'envelope_id' => $event->envelopeId, 'permanent' => $event->permanentCryptoShred]);
        });

        PendingRequest::macro('residencyGuarded', function (string $purpose, ?string $explicitCountry = null): PendingRequest {
            /** @var PendingRequest $this */
            return $this->withRequestMiddleware(static function (RequestInterface $request) use ($purpose, $explicitCountry): RequestInterface {
                $tenantId = app(TenantContext::class)->getTenantId();

                if ($tenantId !== null) {
                    app(CrossBorderEgressGuard::class)->assertAllowed($tenantId, app(ResidencyRuntime::class)->legalEntityId(), $purpose, (string) $request->getUri(), $explicitCountry);
                }

                return $request;
            });
        });
    }
}
