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
use Modules\Payroll\Listeners\FeedPublishedRosterIntoSwissPayroll;
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
use Modules\Payroll\Swissdec\SalaryDeclarationTransmitter;
use Modules\Payroll\Swissdec\UncertifiedTransmitterStub;
use Modules\ShiftPlanning\Domain\Events\RosterPublishedEvent;
use Modules\ShiftPlanning\Domain\Events\ShiftAssignmentFinalizedEvent;

final class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SalaryDeclarationTransmitter::class, UncertifiedTransmitterStub::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
            $this->loadRoutesFrom(__DIR__.'/../../routes/compliance.php');
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
        // Mod 073 surcharge minutes of published rosters become Swiss variable inputs (Mod 076); Mod 077 reads the same rosters.
        Event::listen(RosterPublishedEvent::class, [FeedPublishedRosterIntoSwissPayroll::class, 'handleRosterPublished']);
        Event::listen(ShiftAssignmentFinalizedEvent::class, [FeedPublishedRosterIntoSwissPayroll::class, 'handleAssignmentFinalized']);

        if ($this->app->runningInConsole()) {
            $this->commands([ImportGenericTariffsCommand::class]);
        }
    }
}
