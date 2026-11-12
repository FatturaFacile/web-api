<?php

namespace App\Providers;

use App\Contracts\ExchangeRateProviderInterface;
use App\Contracts\FiscalPrinterGateway;
use App\Contracts\PdfGenerator;
use App\Contracts\SdiProvider;
use App\Contracts\Security\HostResolver;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Policies\AppointmentPolicy;
use App\Policies\ContactPolicy;
use App\Policies\DeliveryNotePolicy;
use App\Policies\DiscountPolicyPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\QuotePolicy;
use App\Policies\SalesOrderPolicy;
use App\Policies\SalesReturnPolicy;
use App\Policies\ServiceReportPolicy;
use App\Policies\WorkOrderPolicy;
use App\Services\Dashboard\WidgetRegistry;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\ExchangeRate\EuropeanCentralBankProvider;
use App\Services\Pdf\DompdfPdfGenerator;
use App\Services\Retail\LocalFiscalPrinterGateway;
use App\Services\Security\DnsHostResolver;
use App\Services\Security\OutboundUrlGuard;
use App\Services\Tenancy\DocumentQuotaService;
use App\Services\Tenancy\TenantContext;
use App\Support\Database\SafeDropPostgresConnection;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Modules\Invoicing\Models\DiscountPolicy;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SDI\Services\TenantSdiProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole() || PHP_SAPI === 'cli-server') {
            // Never lower a limit the environment set higher (CI coverage runs with 2G): only raise a smaller one.
            $currentLimit = ini_parse_quantity((string) ini_get('memory_limit'));

            if ($currentLimit !== -1 && $currentLimit < ini_parse_quantity('1G')) {
                @ini_set('memory_limit', '1G');
            }
        }

        $this->app->singleton(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(ExchangeRateProviderInterface::class, EuropeanCentralBankProvider::class);
        $this->app->bind(SdiProvider::class, TenantSdiProvider::class);
        $this->app->bind(FiscalPrinterGateway::class, LocalFiscalPrinterGateway::class);
        $this->app->bind(PdfGenerator::class, DompdfPdfGenerator::class);
        $this->app->singleton(EcommerceManager::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        // The allow-list of outbound hosts is a local development convenience: ignored in every other environment.
        $this->app->bind(OutboundUrlGuard::class, fn ($app): OutboundUrlGuard => new OutboundUrlGuard(
            $app->make(HostResolver::class),
            $app->environment('local') ? (array) config('security.outbound.allowed_hosts', []) : [],
        ));
        $this->app->singleton(WidgetRegistry::class);

        Connection::resolverFor('pgsql', static fn ($connection, $database, $prefix, $config): PostgresConnection => new SafeDropPostgresConnection($connection, $database, $prefix, $config));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The quota is checked here so every path that creates an invoice stops at the same limit.
        // Both models write the `invoices` table: the module SalesInvoice is the one InvoicingService uses.
        $assertInvoiceQuota = static function (Invoice|SalesInvoice $invoice): void {
            $tenant = $invoice->invoice_type === 'proforma' || $invoice->tenant_id === null ? null : Tenant::find($invoice->tenant_id);

            if ($tenant !== null) {
                app(DocumentQuotaService::class)->assertCanIssue($tenant, DocumentQuotaService::InvoicePoints);
            }
        };
        Invoice::creating($assertInvoiceQuota);
        SalesInvoice::creating($assertInvoiceQuota);

        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(DeliveryNote::class, DeliveryNotePolicy::class);
        Gate::policy(SalesReturn::class, SalesReturnPolicy::class);
        Gate::policy(DiscountPolicy::class, DiscountPolicyPolicy::class);
        Gate::policy(SalesOrder::class, SalesOrderPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(ServiceReport::class, ServiceReportPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);

        Password::defaults(function (): Password {
            $rule = Password::min((int) config('auth.registration.password.min_length'));

            if (config('auth.registration.password.mixed_case')) {
                $rule = $rule->mixedCase();
            }

            if (config('auth.registration.password.numbers')) {
                $rule = $rule->numbers();
            }

            if (config('auth.registration.password.symbols')) {
                $rule = $rule->symbols();
            }

            return $rule;
        });

        $emailKey = function (Request $request): string {
            $email = $request->input('email');

            return 'email:'.(is_string($email) ? mb_strtolower(trim($email)) : '');
        };

        // Credential guessing: per account from anywhere, and per address on any account.
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by('account:'.Str::lower((string) $request->input('email'))),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(300)->by($request->user()?->getKey() ?: $request->ip()));
        // Routes of the stateful `web` group (Sanctum csrf-cookie, docs, root, health) open a session row per request:
        // csrf-cookie is the one a SPA hits once per login, so 60/min per address is generous; the rest gets 240/min.
        RateLimiter::for('web-public', fn (Request $request): Limit => $request->routeIs('sanctum.csrf-cookie')
            ? Limit::perMinute(60)->by('csrf-cookie|'.$request->ip())
            : Limit::perMinute(240)->by('web|'.$request->ip()));
        RateLimiter::for('registration', fn (Request $request): array => [
            Limit::perMinute((int) config('auth.registration.rate_limits.register_per_minute'))->by('ip:'.$request->ip()),
            Limit::perHour((int) config('auth.registration.rate_limits.register_per_email_per_hour'))->by($emailKey($request)),
        ]);
        RateLimiter::for('email-verification', fn (Request $request): Limit => Limit::perMinute((int) config('auth.registration.rate_limits.verify_per_minute'))->by($request->ip()));
        RateLimiter::for('verification-resend', fn (Request $request): array => [
            Limit::perMinute((int) config('auth.registration.rate_limits.resend_per_minute'))->by('ip:'.$request->ip()),
            Limit::perHour((int) config('auth.registration.rate_limits.resend_per_hour'))->by($emailKey($request)),
        ]);

        RateLimiter::for('password-reset-request', fn (Request $request): Limit => Limit::perMinute((int) config('auth.password_reset.rate_limits.request_per_minute'))->by($request->ip()));
        RateLimiter::for('password-reset-attempt', fn (Request $request): Limit => Limit::perMinute((int) config('auth.password_reset.rate_limits.attempts_per_minute'))->by($request->ip()));
        RateLimiter::for('password-change', fn (Request $request): Limit => Limit::perMinute((int) config('auth.password_change.rate_limits.attempts_per_minute'))->by('user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('two-factor-challenge', fn (Request $request): array => [
            Limit::perMinute((int) config('auth.two_factor.rate_limits.challenge_per_minute'))->by('challenge:'.hash('sha256', (string) $request->input('challenge_token'))),
            Limit::perMinute((int) config('auth.two_factor.rate_limits.challenge_per_minute') * 3)->by('ip:'.$request->ip()),
        ]);

        Scramble::afterOpenApiGenerated(function ($openApi) {});

        // Platform administrators, or the addresses listed in config/docs.php (DOCS_ALLOWED_EMAILS).
        Gate::define('viewApiDocs', static fn (User $user): bool => $user->is_platform_admin
            || in_array(strtolower((string) $user->email), (array) config('docs.allowed_emails', []), true));
    }
}
