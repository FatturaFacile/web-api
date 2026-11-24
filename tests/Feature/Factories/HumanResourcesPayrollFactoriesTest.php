<?php

declare(strict_types=1);

use Database\Factories\ApplicationStageEventFactory;
use Database\Factories\CclConstructionParameterFactory;
use Database\Factories\CclWorkCalendarFactory;
use Database\Factories\CollectiveAgreementLevelFactory;
use Database\Factories\HumanResourcesShiftFactory;
use Database\Factories\InterviewNoteFactory;
use Database\Factories\ItalianPayrollSettingsFactory;
use Database\Factories\JobApplicationFactory;
use Database\Factories\JobPostingFactory;
use Database\Factories\OnboardingChecklistFactory;
use Database\Factories\OnboardingTaskFactory;
use Database\Factories\OnboardingTemplateFactory;
use Database\Factories\PayrollAbsenceFactory;
use Database\Factories\PayrollAnnualParameterFactory;
use Database\Factories\PayrollEmployeeChildFactory;
use Database\Factories\PayrollFamilyAllowanceRateFactory;
use Database\Factories\PayrollFrontierYearFactory;
use Database\Factories\PayrollInputFactory;
use Database\Factories\PayrollLegalParameterFactory;
use Database\Factories\PayrollPaymentBatchFactory;
use Database\Factories\PayrollRunEventFactory;
use Database\Factories\PayrollWageTypeFactory;
use Database\Factories\PayrollWithholdingStatementFactory;
use Database\Factories\PayrollWithholdingTaxTariffFactory;
use Database\Factories\PayslipDeliveryFactory;
use Database\Factories\PayslipFactory;
use Database\Factories\PayslipLineFactory;
use Database\Factories\PipelineStageFactory;
use Database\Factories\RecruitingSettingFactory;
use Database\Factories\SalaryCertificateFactory;
use Database\Factories\ShiftExceptionFactory;
use Database\Factories\ShiftPublicationFactory;
use Database\Factories\ShiftSequenceAssignmentFactory;
use Database\Factories\ShiftSequenceFactory;
use Database\Factories\ShiftTypeFactory;
use Database\Factories\ShortTimeCaseFactory;
use Database\Factories\ShortTimeClaimFactory;
use Database\Factories\ShortTimeLineFactory;
use Database\Factories\StaffingNeedFactory;
use Database\Factories\SwissPayrollSettingsFactory;
use Database\Factories\TimeAuditEntryFactory;
use Database\Factories\TimeBadgeFactory;
use Database\Factories\TimeMealBookingFactory;
use Database\Factories\TimePublicHolidayFactory;
use Database\Factories\TimePunchImportFactory;
use Database\Factories\TimeScheduleGroupFactory;
use Database\Factories\TimeScheduleGroupMemberFactory;
use Database\Factories\TimeScheduleGroupRuleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\HumanResources\Models\ApplicationStageEvent;
use Modules\HumanResources\Models\InterviewNote;
use Modules\HumanResources\Models\JobApplication;
use Modules\HumanResources\Models\JobPosting;
use Modules\HumanResources\Models\OnboardingChecklist;
use Modules\HumanResources\Models\OnboardingTask;
use Modules\HumanResources\Models\OnboardingTemplate;
use Modules\HumanResources\Models\PipelineStage;
use Modules\HumanResources\Models\RecruitingSetting;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\ShiftException;
use Modules\HumanResources\Models\ShiftPublication;
use Modules\HumanResources\Models\ShiftSequence;
use Modules\HumanResources\Models\ShiftSequenceAssignment;
use Modules\HumanResources\Models\ShiftType;
use Modules\HumanResources\Models\StaffingNeed;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeBadge;
use Modules\HumanResources\Models\TimeMealBooking;
use Modules\HumanResources\Models\TimePublicHoliday;
use Modules\HumanResources\Models\TimePunchImport;
use Modules\HumanResources\Models\TimeScheduleGroup;
use Modules\HumanResources\Models\TimeScheduleGroupMember;
use Modules\HumanResources\Models\TimeScheduleGroupRule;
use Modules\Payroll\Models\CclConstructionParameter;
use Modules\Payroll\Models\CclWorkCalendar;
use Modules\Payroll\Models\CollectiveAgreementLevel;
use Modules\Payroll\Models\ItalianPayrollSettings;
use Modules\Payroll\Models\PayrollAbsence;
use Modules\Payroll\Models\PayrollAnnualParameter;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;
use Modules\Payroll\Models\PayrollFrontierYear;
use Modules\Payroll\Models\PayrollInput;
use Modules\Payroll\Models\PayrollLegalParameter;
use Modules\Payroll\Models\PayrollPaymentBatch;
use Modules\Payroll\Models\PayrollRunEvent;
use Modules\Payroll\Models\PayrollWageType;
use Modules\Payroll\Models\PayrollWithholdingStatement;
use Modules\Payroll\Models\PayrollWithholdingTaxTariff;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipDelivery;
use Modules\Payroll\Models\PayslipLine;
use Modules\Payroll\Models\SalaryCertificate;
use Modules\Payroll\Models\ShortTimeCase;
use Modules\Payroll\Models\ShortTimeClaim;
use Modules\Payroll\Models\ShortTimeLine;
use Modules\Payroll\Models\SwissPayrollSettings;

uses(RefreshDatabase::class);

dataset('human resources and payroll factories', [
    'ApplicationStageEvent' => [ApplicationStageEvent::class, ApplicationStageEventFactory::class],
    'InterviewNote' => [InterviewNote::class, InterviewNoteFactory::class],
    'JobApplication' => [JobApplication::class, JobApplicationFactory::class],
    'JobPosting' => [JobPosting::class, JobPostingFactory::class],
    'OnboardingChecklist' => [OnboardingChecklist::class, OnboardingChecklistFactory::class],
    'OnboardingTask' => [OnboardingTask::class, OnboardingTaskFactory::class],
    'OnboardingTemplate' => [OnboardingTemplate::class, OnboardingTemplateFactory::class],
    'PipelineStage' => [PipelineStage::class, PipelineStageFactory::class],
    'RecruitingSetting' => [RecruitingSetting::class, RecruitingSettingFactory::class],
    'Shift' => [Shift::class, HumanResourcesShiftFactory::class],
    'ShiftException' => [ShiftException::class, ShiftExceptionFactory::class],
    'ShiftPublication' => [ShiftPublication::class, ShiftPublicationFactory::class],
    'ShiftSequence' => [ShiftSequence::class, ShiftSequenceFactory::class],
    'ShiftSequenceAssignment' => [ShiftSequenceAssignment::class, ShiftSequenceAssignmentFactory::class],
    'ShiftType' => [ShiftType::class, ShiftTypeFactory::class],
    'StaffingNeed' => [StaffingNeed::class, StaffingNeedFactory::class],
    'TimeBadge' => [TimeBadge::class, TimeBadgeFactory::class],
    'TimeMealBooking' => [TimeMealBooking::class, TimeMealBookingFactory::class],
    'TimePunchImport' => [TimePunchImport::class, TimePunchImportFactory::class],
    'TimeScheduleGroup' => [TimeScheduleGroup::class, TimeScheduleGroupFactory::class],
    'TimeScheduleGroupMember' => [TimeScheduleGroupMember::class, TimeScheduleGroupMemberFactory::class],
    'TimeScheduleGroupRule' => [TimeScheduleGroupRule::class, TimeScheduleGroupRuleFactory::class],
    'TimeAuditEntry' => [TimeAuditEntry::class, TimeAuditEntryFactory::class],
    'TimePublicHoliday' => [TimePublicHoliday::class, TimePublicHolidayFactory::class],
    'CclConstructionParameter' => [CclConstructionParameter::class, CclConstructionParameterFactory::class],
    'CclWorkCalendar' => [CclWorkCalendar::class, CclWorkCalendarFactory::class],
    'PayrollFrontierYear' => [PayrollFrontierYear::class, PayrollFrontierYearFactory::class],
    'PayrollLegalParameter' => [PayrollLegalParameter::class, PayrollLegalParameterFactory::class],
    'PayrollPaymentBatch' => [PayrollPaymentBatch::class, PayrollPaymentBatchFactory::class],
    'PayrollWithholdingStatement' => [PayrollWithholdingStatement::class, PayrollWithholdingStatementFactory::class],
    'PayslipDelivery' => [PayslipDelivery::class, PayslipDeliveryFactory::class],
    'ShortTimeCase' => [ShortTimeCase::class, ShortTimeCaseFactory::class],
    'ShortTimeClaim' => [ShortTimeClaim::class, ShortTimeClaimFactory::class],
    'ShortTimeLine' => [ShortTimeLine::class, ShortTimeLineFactory::class],
    'CollectiveAgreementLevel' => [CollectiveAgreementLevel::class, CollectiveAgreementLevelFactory::class],
    'ItalianPayrollSettings' => [ItalianPayrollSettings::class, ItalianPayrollSettingsFactory::class],
    'PayrollAbsence' => [PayrollAbsence::class, PayrollAbsenceFactory::class],
    'PayrollAnnualParameter' => [PayrollAnnualParameter::class, PayrollAnnualParameterFactory::class],
    'PayrollEmployeeChild' => [PayrollEmployeeChild::class, PayrollEmployeeChildFactory::class],
    'PayrollFamilyAllowanceRate' => [PayrollFamilyAllowanceRate::class, PayrollFamilyAllowanceRateFactory::class],
    'PayrollInput' => [PayrollInput::class, PayrollInputFactory::class],
    'PayrollRunEvent' => [PayrollRunEvent::class, PayrollRunEventFactory::class],
    'PayrollWageType' => [PayrollWageType::class, PayrollWageTypeFactory::class],
    'PayrollWithholdingTaxTariff' => [PayrollWithholdingTaxTariff::class, PayrollWithholdingTaxTariffFactory::class],
    'Payslip' => [Payslip::class, PayslipFactory::class],
    'PayslipLine' => [PayslipLine::class, PayslipLineFactory::class],
    'SalaryCertificate' => [SalaryCertificate::class, SalaryCertificateFactory::class],
    'SwissPayrollSettings' => [SwissPayrollSettings::class, SwissPayrollSettingsFactory::class],
]);

test('every model of the group has a working factory', function (string $model, string $factory): void {
    $record = $factory::new()->create();

    expect($record)->toBeInstanceOf($model)->and($record->exists)->toBeTrue();
})->with('human resources and payroll factories');

test('every model of the group resolves its own factory', function (string $model, string $factory): void {
    expect($model::factory())->toBeInstanceOf($factory);
})->with('human resources and payroll factories');
