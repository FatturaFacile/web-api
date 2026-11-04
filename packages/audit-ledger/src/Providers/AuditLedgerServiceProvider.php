<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Providers;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Listeners\AuthAuditListener;
use Enterprise\AuditLedger\Observers\ContactAuditObserver;
use Enterprise\AuditLedger\Observers\InvoiceAuditObserver;
use Enterprise\AuditLedger\Observers\PaymentAuditObserver;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AuditLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLedgerInterface::class, AuditLedgerService::class);
        $this->app->singleton(AuditLedgerService::class);
        $this->app->singleton(AuditChainVerificationService::class);
    }

    public function boot(): void
    {
        Invoice::observe(InvoiceAuditObserver::class);
        Contact::observe(ContactAuditObserver::class);
        Payment::observe(PaymentAuditObserver::class);

        Event::listen(Login::class, [AuthAuditListener::class, 'handleLogin']);
        Event::listen(Logout::class, [AuthAuditListener::class, 'handleLogout']);
        Event::listen(Failed::class, [AuthAuditListener::class, 'handleFailed']);
    }
}
