<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\ElectronicSignature\Contracts\SmsGateway;
use Modules\ElectronicSignature\Events\SignatureEnvelopeCompleted;
use Modules\ElectronicSignature\Events\SignatureEnvelopeDeclined;
use Modules\ElectronicSignature\Events\SignatureEnvelopeExpired;
use Modules\ElectronicSignature\Listeners\ApplySignatureOutcome;
use Modules\ElectronicSignature\Listeners\ArchiveSignedDocument;
use Modules\ElectronicSignature\Policies\ElectronicSignaturePolicy;
use Modules\ElectronicSignature\Services\HttpSmsGateway;
use Modules\ElectronicSignature\Services\ProviderRegistry;
use Modules\ElectronicSignature\Services\SignableDocumentRegistry;
use Modules\ElectronicSignature\Services\SignatureTextResolver;

/**
 * Electronic signatures (FES / FEA / FEQ, eIDAS and ZertES): routes, the three abilities, the throttles of the public
 * portal and the listeners that apply the outcome of an envelope to its document and archive it.
 */
final class ElectronicSignatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsGateway::class, HttpSmsGateway::class);
        // The resolvers cache what they read; a scoped binding gives every request and queued job a fresh cache.
        $this->app->scoped(SignatureTextResolver::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(SignableDocumentRegistry::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Gate::define('signatures.view', [ElectronicSignaturePolicy::class, 'viewAny']);
        Gate::define('signatures.send', [ElectronicSignaturePolicy::class, 'send']);
        Gate::define('signatures.configure', [ElectronicSignaturePolicy::class, 'configure']);

        RateLimiter::for('electronic-signature-portal', static fn (Request $request): Limit => Limit::perMinute((int) config('electronic_signature.portal.throttle', 30))->by('esig-portal:'.$request->ip()));
        RateLimiter::for('electronic-signature-challenge', static fn (Request $request): Limit => Limit::perMinute((int) config('electronic_signature.portal.challenge_throttle', 5))->by('esig-challenge:'.$request->ip().':'.hash('sha256', (string) $request->route('token'))));

        Event::listen(SignatureEnvelopeCompleted::class, [ApplySignatureOutcome::class, 'handleCompleted']);
        Event::listen(SignatureEnvelopeDeclined::class, [ApplySignatureOutcome::class, 'handleDeclined']);
        Event::listen(SignatureEnvelopeExpired::class, [ApplySignatureOutcome::class, 'handleExpired']);
        Event::listen(SignatureEnvelopeCompleted::class, [ArchiveSignedDocument::class, 'handle']);
    }
}
