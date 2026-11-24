<?php

declare(strict_types=1);

use Database\Factories\AssociationsPaymentLinkFactory;
use Database\Factories\AssociationsShiftFactory;
use Database\Factories\GeneralInterestActivityFactory;
use Database\Factories\GuardianshipFactory;
use Database\Factories\ImportBatchFactory;
use Database\Factories\LegalParameterFactory;
use Database\Factories\Lex398EntryFactory;
use Database\Factories\MeetingAgendaItemFactory;
use Database\Factories\MeetingAttachmentFactory;
use Database\Factories\MeetingParticipantFactory;
use Database\Factories\MemberDocumentFactory;
use Database\Factories\MembershipFactory;
use Database\Factories\MemberSocialRoleFactory;
use Database\Factories\MinutesFactory;
use Database\Factories\MissionReportFactory;
use Database\Factories\NetworkLinkFactory;
use Database\Factories\NoticeFactory;
use Database\Factories\PrivacySubjectFactory;
use Database\Factories\ProtocolEntryFactory;
use Database\Factories\ReceiptFactory;
use Database\Factories\RegistryAssignmentFactory;
use Database\Factories\ReimbursementAttachmentFactory;
use Database\Factories\ReimbursementFactory;
use Database\Factories\ResourceBookingFactory;
use Database\Factories\SectionFactory;
use Database\Factories\ShiftAssignmentFactory;
use Database\Factories\ShiftAvailabilityFactory;
use Database\Factories\SocialRoleFactory;
use Database\Factories\SponsorContractFactory;
use Database\Factories\SponsorFactory;
use Database\Factories\SportCollaboratorFactory;
use Database\Factories\SportCompensationFactory;
use Database\Factories\SportContractFactory;
use Database\Factories\StatementFilingFactory;
use Database\Factories\StatementMappingFactory;
use Database\Factories\TicketTypeFactory;
use Database\Factories\VolunteerTrainingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Associations\Models\GeneralInterestActivity;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\ImportBatch;
use Modules\Associations\Models\LegalParameter;
use Modules\Associations\Models\Lex398Entry;
use Modules\Associations\Models\MeetingAgendaItem;
use Modules\Associations\Models\MeetingAttachment;
use Modules\Associations\Models\MeetingParticipant;
use Modules\Associations\Models\MemberDocument;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\MemberSocialRole;
use Modules\Associations\Models\Minutes;
use Modules\Associations\Models\MissionReport;
use Modules\Associations\Models\NetworkLink;
use Modules\Associations\Models\Notice;
use Modules\Associations\Models\PaymentLink;
use Modules\Associations\Models\PrivacySubject;
use Modules\Associations\Models\ProtocolEntry;
use Modules\Associations\Models\Receipt;
use Modules\Associations\Models\RegistryAssignment;
use Modules\Associations\Models\Reimbursement;
use Modules\Associations\Models\ReimbursementAttachment;
use Modules\Associations\Models\ResourceBooking;
use Modules\Associations\Models\Section;
use Modules\Associations\Models\Shift;
use Modules\Associations\Models\ShiftAssignment;
use Modules\Associations\Models\ShiftAvailability;
use Modules\Associations\Models\SocialRole;
use Modules\Associations\Models\Sponsor;
use Modules\Associations\Models\SponsorContract;
use Modules\Associations\Models\SportCollaborator;
use Modules\Associations\Models\SportCompensation;
use Modules\Associations\Models\SportContract;
use Modules\Associations\Models\StatementFiling;
use Modules\Associations\Models\StatementMapping;
use Modules\Associations\Models\TicketType;
use Modules\Associations\Models\VolunteerTraining;

uses(RefreshDatabase::class);

test('every model of the group has a working factory', function (string $model, string $factory): void {
    $record = $factory::new()->create();

    expect($record)->toBeInstanceOf($model)->and($record->exists)->toBeTrue();
})->with([
    'GeneralInterestActivity' => [GeneralInterestActivity::class, GeneralInterestActivityFactory::class],
    'Guardianship' => [Guardianship::class, GuardianshipFactory::class],
    'ImportBatch' => [ImportBatch::class, ImportBatchFactory::class],
    'LegalParameter' => [LegalParameter::class, LegalParameterFactory::class],
    'Lex398Entry' => [Lex398Entry::class, Lex398EntryFactory::class],
    'MeetingAgendaItem' => [MeetingAgendaItem::class, MeetingAgendaItemFactory::class],
    'MeetingAttachment' => [MeetingAttachment::class, MeetingAttachmentFactory::class],
    'MeetingParticipant' => [MeetingParticipant::class, MeetingParticipantFactory::class],
    'MemberDocument' => [MemberDocument::class, MemberDocumentFactory::class],
    'MemberSocialRole' => [MemberSocialRole::class, MemberSocialRoleFactory::class],
    'Membership' => [Membership::class, MembershipFactory::class],
    'Minutes' => [Minutes::class, MinutesFactory::class],
    'MissionReport' => [MissionReport::class, MissionReportFactory::class],
    'NetworkLink' => [NetworkLink::class, NetworkLinkFactory::class],
    'Notice' => [Notice::class, NoticeFactory::class],
    'PaymentLink' => [PaymentLink::class, AssociationsPaymentLinkFactory::class],
    'PrivacySubject' => [PrivacySubject::class, PrivacySubjectFactory::class],
    'ProtocolEntry' => [ProtocolEntry::class, ProtocolEntryFactory::class],
    'Receipt' => [Receipt::class, ReceiptFactory::class],
    'RegistryAssignment' => [RegistryAssignment::class, RegistryAssignmentFactory::class],
    'Reimbursement' => [Reimbursement::class, ReimbursementFactory::class],
    'ReimbursementAttachment' => [ReimbursementAttachment::class, ReimbursementAttachmentFactory::class],
    'ResourceBooking' => [ResourceBooking::class, ResourceBookingFactory::class],
    'Section' => [Section::class, SectionFactory::class],
    'Shift' => [Shift::class, AssociationsShiftFactory::class],
    'ShiftAssignment' => [ShiftAssignment::class, ShiftAssignmentFactory::class],
    'ShiftAvailability' => [ShiftAvailability::class, ShiftAvailabilityFactory::class],
    'SocialRole' => [SocialRole::class, SocialRoleFactory::class],
    'Sponsor' => [Sponsor::class, SponsorFactory::class],
    'SponsorContract' => [SponsorContract::class, SponsorContractFactory::class],
    'SportCollaborator' => [SportCollaborator::class, SportCollaboratorFactory::class],
    'SportCompensation' => [SportCompensation::class, SportCompensationFactory::class],
    'SportContract' => [SportContract::class, SportContractFactory::class],
    'StatementFiling' => [StatementFiling::class, StatementFilingFactory::class],
    'StatementMapping' => [StatementMapping::class, StatementMappingFactory::class],
    'TicketType' => [TicketType::class, TicketTypeFactory::class],
    'VolunteerTraining' => [VolunteerTraining::class, VolunteerTrainingFactory::class],
]);

test('every wired model resolves its own factory', function (string $model, string $factory): void {
    expect($model::factory())->toBeInstanceOf($factory);
})->with([
    'GeneralInterestActivity' => [GeneralInterestActivity::class, GeneralInterestActivityFactory::class],
    'Guardianship' => [Guardianship::class, GuardianshipFactory::class],
    'ImportBatch' => [ImportBatch::class, ImportBatchFactory::class],
    'LegalParameter' => [LegalParameter::class, LegalParameterFactory::class],
    'Lex398Entry' => [Lex398Entry::class, Lex398EntryFactory::class],
    'MeetingAgendaItem' => [MeetingAgendaItem::class, MeetingAgendaItemFactory::class],
    'MeetingAttachment' => [MeetingAttachment::class, MeetingAttachmentFactory::class],
    'MeetingParticipant' => [MeetingParticipant::class, MeetingParticipantFactory::class],
    'MemberDocument' => [MemberDocument::class, MemberDocumentFactory::class],
    'MemberSocialRole' => [MemberSocialRole::class, MemberSocialRoleFactory::class],
    'Membership' => [Membership::class, MembershipFactory::class],
    'Minutes' => [Minutes::class, MinutesFactory::class],
    'MissionReport' => [MissionReport::class, MissionReportFactory::class],
    'NetworkLink' => [NetworkLink::class, NetworkLinkFactory::class],
    'Notice' => [Notice::class, NoticeFactory::class],
    'PrivacySubject' => [PrivacySubject::class, PrivacySubjectFactory::class],
    'ProtocolEntry' => [ProtocolEntry::class, ProtocolEntryFactory::class],
    'RegistryAssignment' => [RegistryAssignment::class, RegistryAssignmentFactory::class],
    'Reimbursement' => [Reimbursement::class, ReimbursementFactory::class],
    'ReimbursementAttachment' => [ReimbursementAttachment::class, ReimbursementAttachmentFactory::class],
    'ResourceBooking' => [ResourceBooking::class, ResourceBookingFactory::class],
    'Section' => [Section::class, SectionFactory::class],
    'Shift' => [Shift::class, AssociationsShiftFactory::class],
    'ShiftAssignment' => [ShiftAssignment::class, ShiftAssignmentFactory::class],
    'ShiftAvailability' => [ShiftAvailability::class, ShiftAvailabilityFactory::class],
    'SocialRole' => [SocialRole::class, SocialRoleFactory::class],
    'Sponsor' => [Sponsor::class, SponsorFactory::class],
    'SponsorContract' => [SponsorContract::class, SponsorContractFactory::class],
    'SportCollaborator' => [SportCollaborator::class, SportCollaboratorFactory::class],
    'SportCompensation' => [SportCompensation::class, SportCompensationFactory::class],
    'SportContract' => [SportContract::class, SportContractFactory::class],
    'StatementFiling' => [StatementFiling::class, StatementFilingFactory::class],
    'StatementMapping' => [StatementMapping::class, StatementMappingFactory::class],
    'TicketType' => [TicketType::class, TicketTypeFactory::class],
    'VolunteerTraining' => [VolunteerTraining::class, VolunteerTrainingFactory::class],
]);
