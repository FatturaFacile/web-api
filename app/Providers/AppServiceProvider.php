<?php

namespace App\Providers;

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
use App\Services\ItalianSdi\LocalSdiProvider;
use App\Services\Pdf\DompdfPdfGenerator;
use App\Services\Retail\LocalFiscalPrinterGateway;
use App\Services\Tenancy\TenantContext;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(SdiProvider::class, LocalSdiProvider::class);
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

        Scramble::afterOpenApiGenerated(function ($openApi) {});

        Gate::define('viewApiDocs', function ($user) {
            return in_array($user->email, ['tuamela@example.com']);
        });
    }
}
