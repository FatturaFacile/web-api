<?php

declare(strict_types=1);

use Database\Factories\AccessDeviceFactory;
use Database\Factories\AccessLogFactory;
use Database\Factories\AreaPresenceFactory;
use Database\Factories\AssociationDeliveryFactory;
use Database\Factories\AssociationsAttendanceFactory;
use Database\Factories\AssociationSettingFactory;
use Database\Factories\AssociationsExpenseCategoryFactory;
use Database\Factories\AuthorizationFactory;
use Database\Factories\BallotFactory;
use Database\Factories\BallotVoteFactory;
use Database\Factories\BallotVoterFactory;
use Database\Factories\CampaignExpenseFactory;
use Database\Factories\ConsentFactory;
use Database\Factories\CourseEnrolmentFactory;
use Database\Factories\CourseInstructorFactory;
use Database\Factories\CourseScheduleFactory;
use Database\Factories\CourseSuspensionFactory;
use Database\Factories\CredentialFactory;
use Database\Factories\CustomFieldFactory;
use Database\Factories\DataAssetFactory;
use Database\Factories\DataSubjectRequestFactory;
use Database\Factories\DisciplineFactory;
use Database\Factories\DonationFactory;
use Database\Factories\DonationReceiptFactory;
use Database\Factories\DonorFactory;
use Database\Factories\EnrolmentApplicationFactory;
use Database\Factories\EnrolmentFormDocumentFactory;
use Database\Factories\EnrolmentFormFactory;
use Database\Factories\EnrolmentUploadFactory;
use Database\Factories\EventRegistrationFactory;
use Database\Factories\FamilyDiscountFactory;
use Database\Factories\FamilyFactory;
use Database\Factories\FeeAssignmentFactory;
use Database\Factories\FeeInstalmentFactory;
use Database\Factories\FeePaymentFactory;
use Database\Factories\FivePerMilleAllocationFactory;
use Database\Factories\FivePerMilleExpenseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Associations\Models\AccessDevice;
use Modules\Associations\Models\AccessLog;
use Modules\Associations\Models\AreaPresence;
use Modules\Associations\Models\AssociationDelivery;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Attendance;
use Modules\Associations\Models\Authorization;
use Modules\Associations\Models\Ballot;
use Modules\Associations\Models\BallotVote;
use Modules\Associations\Models\BallotVoter;
use Modules\Associations\Models\CampaignExpense;
use Modules\Associations\Models\Consent;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\CourseInstructor;
use Modules\Associations\Models\CourseSchedule;
use Modules\Associations\Models\CourseSuspension;
use Modules\Associations\Models\Credential;
use Modules\Associations\Models\CustomField;
use Modules\Associations\Models\DataAsset;
use Modules\Associations\Models\DataSubjectRequest;
use Modules\Associations\Models\Discipline;
use Modules\Associations\Models\Donation;
use Modules\Associations\Models\DonationReceipt;
use Modules\Associations\Models\Donor;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\EnrolmentFormDocument;
use Modules\Associations\Models\EnrolmentUpload;
use Modules\Associations\Models\EventRegistration;
use Modules\Associations\Models\ExpenseCategory;
use Modules\Associations\Models\Family;
use Modules\Associations\Models\FamilyDiscount;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\FeePayment;
use Modules\Associations\Models\FivePerMilleAllocation;
use Modules\Associations\Models\FivePerMilleExpense;

uses(RefreshDatabase::class);

$wiredModels = [
    [AccessDevice::class, AccessDeviceFactory::class],
    [AccessLog::class, AccessLogFactory::class],
    [AreaPresence::class, AreaPresenceFactory::class],
    [AssociationDelivery::class, AssociationDeliveryFactory::class],
    [AssociationSetting::class, AssociationSettingFactory::class],
    [Attendance::class, AssociationsAttendanceFactory::class],
    [Authorization::class, AuthorizationFactory::class],
    [Ballot::class, BallotFactory::class],
    [BallotVote::class, BallotVoteFactory::class],
    [BallotVoter::class, BallotVoterFactory::class],
    [CampaignExpense::class, CampaignExpenseFactory::class],
    [Consent::class, ConsentFactory::class],
    [CourseEnrolment::class, CourseEnrolmentFactory::class],
    [CourseInstructor::class, CourseInstructorFactory::class],
    [CourseSchedule::class, CourseScheduleFactory::class],
    [CourseSuspension::class, CourseSuspensionFactory::class],
    [Credential::class, CredentialFactory::class],
    [CustomField::class, CustomFieldFactory::class],
    [DataAsset::class, DataAssetFactory::class],
    [DataSubjectRequest::class, DataSubjectRequestFactory::class],
    [Discipline::class, DisciplineFactory::class],
    [Donation::class, DonationFactory::class],
    [DonationReceipt::class, DonationReceiptFactory::class],
    [Donor::class, DonorFactory::class],
    [EnrolmentApplication::class, EnrolmentApplicationFactory::class],
    [EnrolmentForm::class, EnrolmentFormFactory::class],
    [EnrolmentFormDocument::class, EnrolmentFormDocumentFactory::class],
    [EnrolmentUpload::class, EnrolmentUploadFactory::class],
    [EventRegistration::class, EventRegistrationFactory::class],
    [ExpenseCategory::class, AssociationsExpenseCategoryFactory::class],
    [Family::class, FamilyFactory::class],
    [FamilyDiscount::class, FamilyDiscountFactory::class],
    [FivePerMilleAllocation::class, FivePerMilleAllocationFactory::class],
    [FivePerMilleExpense::class, FivePerMilleExpenseFactory::class],
];

$readOnlyModels = [
    [FeeAssignment::class, FeeAssignmentFactory::class],
    [FeeInstalment::class, FeeInstalmentFactory::class],
    [FeePayment::class, FeePaymentFactory::class],
];

test('every model of the group has a working factory', function (string $model, string $factory): void {
    $record = $factory::new()->create();

    expect($record)->toBeInstanceOf($model)->and($record->exists)->toBeTrue();
})->with([...$wiredModels, ...$readOnlyModels]);

test('every wired model resolves its own factory', function (string $model, string $factory): void {
    expect($model::factory())->toBeInstanceOf($factory);
})->with($wiredModels);
