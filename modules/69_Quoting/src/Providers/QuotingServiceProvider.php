<?php

declare(strict_types=1);

namespace Modules\Quoting\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Quoting\Application\Services\SignatureAdapterResolver;
use Modules\Quoting\Domain\Contracts\QuoteDocumentRenderer;
use Modules\Quoting\Domain\Events\QuoteIssued;
use Modules\Quoting\Domain\Events\QuoteOtpRequested;
use Modules\Quoting\Domain\Events\QuoteSignedAndAccepted;
use Modules\Quoting\Infrastructure\Pdf\DompdfQuoteDocumentRenderer;
use Modules\Quoting\Infrastructure\Signature\NativeOtpSignatureAdapter;
use Modules\Quoting\Infrastructure\Signature\QualifiedTspSignatureAdapter;
use Modules\Quoting\Listeners\AutoConvertAcceptedQuote;
use Modules\Quoting\Listeners\SendQuoteNotifications;

/**
 * Interactive quotes and commercial proposals (Mod 036): routes, the signature providers per level, the PDF renderer and the
 * listeners (proposal and OTP delivery, auto-conversion into a sales order on acceptance).
 */
final class QuotingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QuoteDocumentRenderer::class, DompdfQuoteDocumentRenderer::class);
        $this->app->bind(SignatureAdapterResolver::class, fn (): SignatureAdapterResolver => new SignatureAdapterResolver(
            $this->app->make(NativeOtpSignatureAdapter::class),
            $this->app->make(QualifiedTspSignatureAdapter::class),
        ));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Event::listen(QuoteIssued::class, [SendQuoteNotifications::class, 'handleIssued']);
        Event::listen(QuoteOtpRequested::class, [SendQuoteNotifications::class, 'handleOtp']);
        Event::listen(QuoteSignedAndAccepted::class, AutoConvertAcceptedQuote::class);
    }
}
