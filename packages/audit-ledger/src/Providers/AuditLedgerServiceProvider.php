<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Providers;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryHandler;
use Enterprise\AuditLedger\Console\CreateAuditCheckpointCommand;
use Enterprise\AuditLedger\Console\RotateAuditKeyConsoleCommand;
use Enterprise\AuditLedger\Console\VerifyAuditChainCommand;
use Enterprise\AuditLedger\Contracts\AuditAlertGateway;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Contracts\PackageSigner;
use Enterprise\AuditLedger\Domain\Services\AuditKeyring;
use Enterprise\AuditLedger\Domain\Services\ChainHasher;
use Enterprise\AuditLedger\Domain\Services\DiffBuilder;
use Enterprise\AuditLedger\Domain\Services\IntegrityVerifier;
use Enterprise\AuditLedger\Domain\Services\PayloadSanitizer;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Listeners\AuthAuditListener;
use Enterprise\AuditLedger\Models\AuditKeyVersion;
use Enterprise\AuditLedger\Observers\ContactAuditObserver;
use Enterprise\AuditLedger\Observers\InvoiceAuditObserver;
use Enterprise\AuditLedger\Observers\PaymentAuditObserver;
use Enterprise\AuditLedger\Observers\UserAuditObserver;
use Enterprise\AuditLedger\Policies\AuditPolicy;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Enterprise\AuditLedger\Support\ActorResolver;
use Enterprise\AuditLedger\Support\ChainReader;
use Enterprise\AuditLedger\Support\HmacPackageSigner;
use Enterprise\AuditLedger\Support\LogAuditAlertGateway;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AuditLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditKeyring::class, static fn (): AuditKeyring => AuditKeyring::fromConfig(
            static function (string $tenantId): ?int {
                $version = AuditKeyVersion::query()->where('tenant_id', $tenantId)->max('key_version');

                return $version === null ? null : (int) $version;
            },
        ));
        $this->app->singleton(ChainHasher::class);
        $this->app->singleton(DiffBuilder::class);
        $this->app->singleton(Pseudonymizer::class);
        $this->app->singleton(PayloadSanitizer::class, static fn ($app): PayloadSanitizer => PayloadSanitizer::fromConfig($app->make(Pseudonymizer::class)));
        $this->app->singleton(IntegrityVerifier::class);
        $this->app->singleton(ActorResolver::class);
        $this->app->singleton(ChainReader::class);
        $this->app->singleton(AppendAuditEntryHandler::class);

        // Defaults: the HMAC signer and a critical log line. The application binds the document signature service (Mod 144)
        // and the notification engine (Mod 008) over them (see App\Providers\AppServiceProvider): packages never use modules.
        $this->app->singleton(PackageSigner::class, HmacPackageSigner::class);
        $this->app->singleton(AuditAlertGateway::class, LogAuditAlertGateway::class);

        $this->app->singleton(AuditLedgerInterface::class, AuditLedgerService::class);
        $this->app->singleton(AuditLedgerService::class);
        $this->app->singleton(AuditChainVerificationService::class);
    }

    public function boot(): void
    {
        Invoice::observe(InvoiceAuditObserver::class);
        Contact::observe(ContactAuditObserver::class);
        Payment::observe(PaymentAuditObserver::class);
        User::observe(UserAuditObserver::class);

        Event::listen(Login::class, [AuthAuditListener::class, 'handleLogin']);
        Event::listen(Logout::class, [AuthAuditListener::class, 'handleLogout']);
        Event::listen(Failed::class, [AuthAuditListener::class, 'handleFailed']);

        // Who may use the ledger (see AuditPolicy): owner, admin, external auditor and DPO search, verify and export it.
        Gate::define('audit-ledger.search', [AuditPolicy::class, 'search']);
        Gate::define('audit-ledger.export', [AuditPolicy::class, 'export']);
        Gate::define('audit-ledger.verify', [AuditPolicy::class, 'verify']);
        Gate::define('audit-ledger.history', [AuditPolicy::class, 'history']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateAuditCheckpointCommand::class,
                VerifyAuditChainCommand::class,
                RotateAuditKeyConsoleCommand::class,
            ]);
        }
    }
}
