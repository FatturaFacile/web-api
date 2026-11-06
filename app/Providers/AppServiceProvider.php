<?php

namespace App\Providers;

use App\Contracts\ExchangeRateProviderInterface;
use App\Contracts\FiscalPrinterGateway;
use App\Contracts\PdfGenerator;
use App\Contracts\SdiProvider;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\ServiceReport;
use App\Models\WorkOrder;
use App\Policies\AppointmentPolicy;
use App\Policies\ContactPolicy;
use App\Policies\DeliveryNotePolicy;
use App\Policies\ExpensePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\QuotePolicy;
use App\Policies\SalesOrderPolicy;
use App\Policies\ServiceReportPolicy;
use App\Policies\WorkOrderPolicy;
use App\Services\Dashboard\WidgetRegistry;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\ExchangeRate\EuropeanCentralBankProvider;
use App\Services\Pdf\DompdfPdfGenerator;
use App\Services\Retail\LocalFiscalPrinterGateway;
use App\Services\Tenancy\TenantContext;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Modules\SDI\Services\TenantSdiProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(ExchangeRateProviderInterface::class, EuropeanCentralBankProvider::class);
        $this->app->bind(SdiProvider::class, TenantSdiProvider::class);
        $this->app->bind(FiscalPrinterGateway::class, LocalFiscalPrinterGateway::class);
        $this->app->bind(PdfGenerator::class, DompdfPdfGenerator::class);
        $this->app->singleton(EcommerceManager::class);
        $this->app->singleton(WidgetRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(DeliveryNote::class, DeliveryNotePolicy::class);
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

        Scramble::afterOpenApiGenerated(function ($openApi) {});

        Gate::define('viewApiDocs', function ($user) {
            return in_array($user->email, ['tuamela@example.com']);
        });
    }
}
