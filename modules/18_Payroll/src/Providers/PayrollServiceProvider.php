<?php

declare(strict_types=1);

namespace Modules\Payroll\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Payroll\Console\Commands\ImportGenericTariffsCommand;
use Modules\Payroll\Events\PayrollRunFinalizedEvent;
use Modules\Payroll\Events\PayrollRunReopenedEvent;
use Modules\Payroll\Listeners\DispatchOutboxOnPayrollEventListener;
use Modules\Payroll\Models\CollectiveAgreement;
use Modules\Payroll\Models\CollectiveAgreementLevel;
use Modules\Payroll\Models\ItalianPayrollSettings;
use Modules\Payroll\Models\PayrollAbsence;
use Modules\Payroll\Models\PayrollAnnualParameter;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;
use Modules\Payroll\Models\PayrollInput;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\PayrollWageType;
use Modules\Payroll\Models\PayrollWithholdingTaxTariff;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\SalaryCertificate;
use Modules\Payroll\Models\SwissPayrollSettings;
use Modules\Payroll\Policies\EmployeeDocumentPolicy;
use Modules\Payroll\Policies\PayrollPolicy;

final class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'payroll');

        foreach ([
            PayrollRun::class, CollectiveAgreement::class, CollectiveAgreementLevel::class, PayrollWageType::class,
            SwissPayrollSettings::class, ItalianPayrollSettings::class, PayrollAnnualParameter::class,
            PayrollWithholdingTaxTariff::class, PayrollFamilyAllowanceRate::class, PayrollEmployeeProfile::class,
            PayrollEmployeeChild::class, PayrollAbsence::class, PayrollInput::class,
        ] as $model) {
            Gate::policy($model, PayrollPolicy::class);
        }

        Gate::policy(Payslip::class, EmployeeDocumentPolicy::class);
        Gate::policy(SalaryCertificate::class, EmployeeDocumentPolicy::class);

        Event::listen(PayrollRunFinalizedEvent::class, DispatchOutboxOnPayrollEventListener::class);
        Event::listen(PayrollRunReopenedEvent::class, DispatchOutboxOnPayrollEventListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ImportGenericTariffsCommand::class]);
        }
    }
}
