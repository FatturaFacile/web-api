<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Associations\Http\Controllers\AccessAdminController;
use Modules\Associations\Http\Controllers\AccessVerifyController;
use Modules\Associations\Http\Controllers\BallotController;
use Modules\Associations\Http\Controllers\BookingController;
use Modules\Associations\Http\Controllers\CatalogController;
use Modules\Associations\Http\Controllers\CertificateController;
use Modules\Associations\Http\Controllers\CourseController;
use Modules\Associations\Http\Controllers\EnrolmentAdminController;
use Modules\Associations\Http\Controllers\EventController;
use Modules\Associations\Http\Controllers\FeeController;
use Modules\Associations\Http\Controllers\FundraisingController;
use Modules\Associations\Http\Controllers\GuardianController;
use Modules\Associations\Http\Controllers\LegalBookController;
use Modules\Associations\Http\Controllers\Lex398Controller;
use Modules\Associations\Http\Controllers\MeetingController;
use Modules\Associations\Http\Controllers\MemberAreaController;
use Modules\Associations\Http\Controllers\MemberController;
use Modules\Associations\Http\Controllers\MemberDocumentController;
use Modules\Associations\Http\Controllers\MembershipController;
use Modules\Associations\Http\Controllers\MinutesController;
use Modules\Associations\Http\Controllers\NetworkController;
use Modules\Associations\Http\Controllers\PaymentCallbackController;
use Modules\Associations\Http\Controllers\PrivacyController;
use Modules\Associations\Http\Controllers\PublicEnrolmentController;
use Modules\Associations\Http\Controllers\RegistryImportController;
use Modules\Associations\Http\Controllers\ReimbursementController;
use Modules\Associations\Http\Controllers\SectionController;
use Modules\Associations\Http\Controllers\SettingsController;
use Modules\Associations\Http\Controllers\SportWorkController;
use Modules\Associations\Http\Controllers\StatementController;
use Modules\Associations\Http\Controllers\VolunteerController;
use Modules\Associations\Http\Middleware\AuthenticateAccessDevice;
use Modules\Associations\Http\Middleware\AuthenticateMemberArea;
use Modules\Associations\Http\Middleware\ResolveEnrolmentForm;
use Modules\Associations\Services\LegalBookService;

$uuid = '[0-9a-fA-F-]{36}';

Route::prefix('api/v1/associations')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'locale', 'module.access:associations'])
    ->name('api.v1.associations.')
    ->where(['id' => $uuid])
    ->group(function (): void {
        // Configuration
        Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('/catalogs/{catalog}', [CatalogController::class, 'index'])->name('catalogs.index');
        Route::post('/catalogs/{catalog}', [CatalogController::class, 'store'])->name('catalogs.store');
        Route::get('/catalogs/{catalog}/{id}', [CatalogController::class, 'show'])->name('catalogs.show');
        Route::put('/catalogs/{catalog}/{id}', [CatalogController::class, 'update'])->name('catalogs.update');
        Route::delete('/catalogs/{catalog}/{id}', [CatalogController::class, 'destroy'])->name('catalogs.destroy');

        // Registry
        Route::get('/members', [MemberController::class, 'index'])->name('members.index');
        Route::get('/members/export', [MemberController::class, 'export'])->name('members.export');
        Route::post('/members', [MemberController::class, 'store'])->name('members.store');
        Route::get('/members/{id}', [MemberController::class, 'show'])->name('members.show');
        Route::put('/members/{id}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('/members/{id}', [MemberController::class, 'destroy'])->name('members.destroy');
        Route::post('/members/{id}/registry-types', [MemberController::class, 'assignType'])->name('members.registry-types');
        Route::post('/members/{id}/social-roles', [MemberController::class, 'assignRole'])->name('members.social-roles');
        Route::post('/registry-imports', [RegistryImportController::class, 'preview'])->name('registry-imports.preview');
        Route::get('/registry-imports/{id}', [RegistryImportController::class, 'show'])->name('registry-imports.show');
        Route::post('/registry-imports/{id}/commit', [RegistryImportController::class, 'commit'])->name('registry-imports.commit');
        Route::get('/members/{id}/documents', [MemberDocumentController::class, 'index'])->name('members.documents.index');
        Route::post('/members/{id}/documents', [MemberDocumentController::class, 'store'])->name('members.documents.store');
        Route::get('/member-documents/expiring', [MemberDocumentController::class, 'expiring'])->name('member-documents.expiring');
        Route::get('/member-documents/{id}/download', [MemberDocumentController::class, 'download'])->name('member-documents.download');
        Route::delete('/member-documents/{id}', [MemberDocumentController::class, 'destroy'])->name('member-documents.destroy');

        // Minors and the member area links
        Route::get('/members/{id}/guardians', [GuardianController::class, 'index'])->name('members.guardians.index');
        Route::post('/members/{id}/guardians', [GuardianController::class, 'store'])->name('members.guardians.store');
        Route::delete('/guardians/{id}', [GuardianController::class, 'destroy'])->name('guardians.destroy');
        Route::get('/members/{id}/authorizations', [GuardianController::class, 'authorizations'])->name('members.authorizations.index');
        Route::post('/members/{id}/authorizations', [GuardianController::class, 'requestAuthorization'])->name('members.authorizations.store');
        Route::post('/authorizations/{id}/revoke', [GuardianController::class, 'revokeAuthorization'])->name('authorizations.revoke');
        Route::post('/contacts/{id}/area-link', [GuardianController::class, 'sendAreaLink'])->name('contacts.area-link.send');
        Route::delete('/contacts/{id}/area-link', [GuardianController::class, 'revokeAreaLink'])->name('contacts.area-link.revoke');

        // Memberships, cards and credentials
        Route::get('/memberships', [MembershipController::class, 'index'])->name('memberships.index');
        Route::post('/memberships', [MembershipController::class, 'store'])->name('memberships.store');
        Route::get('/memberships/{id}', [MembershipController::class, 'show'])->name('memberships.show');
        Route::post('/memberships/{id}/activate', [MembershipController::class, 'activate'])->name('memberships.activate');
        Route::post('/memberships/{id}/renew', [MembershipController::class, 'renew'])->name('memberships.renew');
        Route::post('/memberships/{id}/suspend', [MembershipController::class, 'suspend'])->name('memberships.suspend');
        Route::post('/memberships/{id}/cease', [MembershipController::class, 'cease'])->name('memberships.cease');
        Route::get('/memberships/{id}/card', [MembershipController::class, 'card'])->name('memberships.card');
        Route::post('/members/{id}/qr', [MembershipController::class, 'reissueQr'])->name('members.qr');
        Route::post('/members/{id}/badges', [MembershipController::class, 'registerBadge'])->name('members.badges');
        Route::delete('/credentials/{id}', [MembershipController::class, 'revokeCredential'])->name('credentials.revoke');

        // Online enrolment
        Route::get('/enrolment-forms', [EnrolmentAdminController::class, 'forms'])->name('enrolment-forms.index');
        Route::post('/enrolment-forms', [EnrolmentAdminController::class, 'storeForm'])->name('enrolment-forms.store');
        Route::get('/enrolment-forms/{id}', [EnrolmentAdminController::class, 'showForm'])->name('enrolment-forms.show');
        Route::put('/enrolment-forms/{id}', [EnrolmentAdminController::class, 'updateForm'])->name('enrolment-forms.update');
        Route::delete('/enrolment-forms/{id}', [EnrolmentAdminController::class, 'destroyForm'])->name('enrolment-forms.destroy');
        Route::post('/enrolment-forms/{id}/documents', [EnrolmentAdminController::class, 'storeFormDocument'])->name('enrolment-forms.documents.store');
        Route::delete('/enrolment-form-documents/{id}', [EnrolmentAdminController::class, 'destroyFormDocument'])->name('enrolment-form-documents.destroy');
        Route::get('/enrolment-applications', [EnrolmentAdminController::class, 'applications'])->name('enrolment-applications.index');
        Route::get('/enrolment-applications/{id}', [EnrolmentAdminController::class, 'showApplication'])->name('enrolment-applications.show');
        Route::post('/enrolment-applications/{id}/approve', [EnrolmentAdminController::class, 'approve'])->name('enrolment-applications.approve');
        Route::post('/enrolment-applications/{id}/reject', [EnrolmentAdminController::class, 'reject'])->name('enrolment-applications.reject');
        Route::get('/enrolment-uploads/{id}/download', [EnrolmentAdminController::class, 'downloadUpload'])->name('enrolment-uploads.download');

        // Fees and payments
        Route::get('/fee-assignments', [FeeController::class, 'assignments'])->name('fee-assignments.index');
        Route::post('/fee-assignments', [FeeController::class, 'assign'])->name('fee-assignments.store');
        Route::post('/fee-assignments/{id}/cancel', [FeeController::class, 'cancel'])->name('fee-assignments.cancel');
        Route::get('/members/{id}/fee-standing', [FeeController::class, 'standing'])->name('members.fee-standing');
        Route::get('/instalments', [FeeController::class, 'instalments'])->name('instalments.index');
        Route::post('/instalments/{id}/payments', [FeeController::class, 'pay'])->name('instalments.payments');
        Route::post('/instalments/{id}/payment-link', [FeeController::class, 'paymentLink'])->name('instalments.payment-link');
        Route::post('/fee-payments/{id}/reverse', [FeeController::class, 'reversePayment'])->name('fee-payments.reverse');
        Route::get('/receipts', [FeeController::class, 'listReceipts'])->name('receipts.index');
        Route::get('/receipts/{id}/pdf', [FeeController::class, 'receiptPdf'])->name('receipts.pdf');

        // Courses and events
        Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
        Route::post('/courses', [CourseController::class, 'store'])->name('courses.store');
        Route::get('/courses/{id}', [CourseController::class, 'show'])->name('courses.show');
        Route::put('/courses/{id}', [CourseController::class, 'update'])->name('courses.update');
        Route::delete('/courses/{id}', [CourseController::class, 'destroy'])->name('courses.destroy');
        Route::get('/courses/{id}/calendar', [CourseController::class, 'calendar'])->name('courses.calendar');
        Route::post('/courses/{id}/schedules', [CourseController::class, 'storeSchedule'])->name('courses.schedules.store');
        Route::delete('/course-schedules/{id}', [CourseController::class, 'destroySchedule'])->name('course-schedules.destroy');
        Route::post('/courses/{id}/suspensions', [CourseController::class, 'storeSuspension'])->name('courses.suspensions.store');
        Route::delete('/course-suspensions/{id}', [CourseController::class, 'destroySuspension'])->name('course-suspensions.destroy');
        Route::post('/courses/{id}/instructors', [CourseController::class, 'assignInstructor'])->name('courses.instructors.store');
        Route::delete('/course-instructors/{id}', [CourseController::class, 'removeInstructor'])->name('course-instructors.destroy');
        Route::get('/courses/{id}/participants', [CourseController::class, 'participants'])->name('courses.participants');
        Route::post('/courses/{id}/enrolments', [CourseController::class, 'enrol'])->name('courses.enrolments.store');
        Route::post('/course-enrolments/{id}/withdraw', [CourseController::class, 'withdraw'])->name('course-enrolments.withdraw');
        Route::get('/courses/{id}/attendance', [CourseController::class, 'attendanceList'])->name('courses.attendance.index');
        Route::post('/courses/{id}/attendance', [CourseController::class, 'recordAttendance'])->name('courses.attendance.store');
        Route::post('/courses/{id}/book-lessons', [CourseController::class, 'bookLessons'])->name('courses.book-lessons');
        Route::post('/courses/{id}/ticket-types', [EventController::class, 'storeTicketType'])->name('courses.ticket-types.store');
        Route::get('/courses/{id}/registrations', [EventController::class, 'registrations'])->name('courses.registrations');
        Route::post('/courses/{id}/check-in', [EventController::class, 'checkIn'])->name('courses.check-in');
        Route::post('/ticket-types/{id}/registrations', [EventController::class, 'register'])->name('ticket-types.registrations.store');
        Route::post('/event-registrations/{id}/cancel', [EventController::class, 'cancel'])->name('event-registrations.cancel');

        // Resource bookings
        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');

        // Medical certificates
        Route::get('/certificates/expired', [CertificateController::class, 'expired'])->name('certificates.expired');
        Route::get('/certificates/expiring', [CertificateController::class, 'expiring'])->name('certificates.expiring');
        Route::get('/certificates/{id}/download', [CertificateController::class, 'download'])->name('certificates.download');
        Route::delete('/certificates/{id}', [CertificateController::class, 'destroy'])->name('certificates.destroy');
        Route::get('/members/{id}/certificates', [CertificateController::class, 'index'])->name('members.certificates.index');
        Route::post('/members/{id}/certificates', [CertificateController::class, 'store'])->name('members.certificates.store');

        // Access control
        Route::get('/access-devices', [AccessAdminController::class, 'index'])->name('access-devices.index');
        Route::post('/access-devices', [AccessAdminController::class, 'store'])->name('access-devices.store');
        Route::post('/access-devices/{id}/rotate-secret', [AccessAdminController::class, 'rotate'])->name('access-devices.rotate-secret');
        Route::post('/access-devices/{id}/deactivate', [AccessAdminController::class, 'deactivate'])->name('access-devices.deactivate');
        Route::get('/access-logs', [AccessAdminController::class, 'logs'])->name('access-logs.index');
        Route::get('/access-areas/{id}/occupancy', [AccessAdminController::class, 'occupancy'])->name('access-areas.occupancy');

        // Network of affiliates
        Route::get('/network/code', [NetworkController::class, 'code'])->name('network.code');
        Route::post('/network/affiliates', [NetworkController::class, 'requestAffiliation'])->name('network.affiliates.request');
        Route::get('/network/consents', [NetworkController::class, 'consents'])->name('network.consents.index');
        Route::post('/network/consents/{id}/grant', [NetworkController::class, 'grant'])->name('network.consents.grant');
        Route::post('/network/consents/{id}/withdraw', [NetworkController::class, 'withdraw'])->name('network.consents.withdraw');
        Route::get('/network/figures', [NetworkController::class, 'figures'])->name('network.figures');

        // Meetings, ballots and minutes
        Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings.index');
        Route::post('/meetings', [MeetingController::class, 'store'])->name('meetings.store');
        Route::get('/meetings/{id}', [MeetingController::class, 'show'])->name('meetings.show');
        Route::put('/meetings/{id}', [MeetingController::class, 'update'])->name('meetings.update');
        Route::delete('/meetings/{id}', [MeetingController::class, 'destroy'])->name('meetings.destroy');
        Route::post('/meetings/{id}/agenda-items', [MeetingController::class, 'addAgendaItem'])->name('meetings.agenda-items.store');
        Route::delete('/agenda-items/{id}', [MeetingController::class, 'removeAgendaItem'])->name('agenda-items.destroy');
        Route::post('/agenda-items/{id}/decision', [MeetingController::class, 'decide'])->name('agenda-items.decision');
        Route::post('/meetings/{id}/attachments', [MeetingController::class, 'attach'])->name('meetings.attachments.store');
        Route::get('/meeting-attachments/{id}/download', [MeetingController::class, 'downloadAttachment'])->name('meeting-attachments.download');
        Route::post('/meetings/{id}/participants', [MeetingController::class, 'addParticipants'])->name('meetings.participants.store');
        Route::delete('/meeting-participants/{id}', [MeetingController::class, 'removeParticipant'])->name('meeting-participants.destroy');
        Route::delete('/meeting-participants/{id}/proxy', [MeetingController::class, 'revokeProxy'])->name('meeting-participants.proxy.destroy');
        Route::post('/meetings/{id}/notices', [MeetingController::class, 'sendNotice'])->name('meetings.notices');
        Route::get('/meetings/{id}/deliveries', [MeetingController::class, 'deliveries'])->name('meetings.deliveries');
        Route::get('/meetings/{id}/letter', [MeetingController::class, 'letter'])->name('meetings.letter');
        Route::post('/meetings/{id}/attendance', [MeetingController::class, 'attendance'])->name('meetings.attendance');
        Route::post('/meetings/{id}/proxies', [MeetingController::class, 'grantProxy'])->name('meetings.proxies');
        Route::get('/meetings/{id}/quorum', [MeetingController::class, 'quorum'])->name('meetings.quorum');
        Route::post('/meetings/{id}/hold', [MeetingController::class, 'hold'])->name('meetings.hold');
        Route::post('/meetings/{id}/close', [MeetingController::class, 'close'])->name('meetings.close');
        Route::post('/meetings/{id}/cancel', [MeetingController::class, 'cancel'])->name('meetings.cancel');
        Route::post('/meetings/{id}/ballots', [BallotController::class, 'store'])->name('meetings.ballots.store');
        Route::get('/ballots/{id}', [BallotController::class, 'show'])->name('ballots.show');
        Route::post('/ballots/{id}/open', [BallotController::class, 'open'])->name('ballots.open');
        Route::post('/ballots/{id}/close', [BallotController::class, 'close'])->name('ballots.close');
        Route::post('/ballots/{id}/votes', [BallotController::class, 'recordVote'])->name('ballots.votes');
        Route::post('/meetings/{id}/minutes', [MinutesController::class, 'store'])->name('meetings.minutes.store');
        Route::get('/minutes/{id}', [MinutesController::class, 'show'])->name('minutes.show');
        Route::put('/minutes/{id}', [MinutesController::class, 'update'])->name('minutes.update');
        Route::post('/minutes/{id}/approve', [MinutesController::class, 'approve'])->name('minutes.approve');
        Route::get('/minutes/{id}/pdf', [MinutesController::class, 'printable'])->name('minutes.pdf');
        Route::post('/minutes/{id}/distribute', [MinutesController::class, 'distribute'])->name('minutes.distribute');
        Route::get('/minutes/{id}/deliveries', [MinutesController::class, 'deliveries'])->name('minutes.deliveries');

        // Books, protocol and archive
        Route::get('/books/{book}', [LegalBookController::class, 'show'])->whereIn('book', LegalBookService::Books)->name('books.show');
        Route::get('/books/{book}/csv', [LegalBookController::class, 'csv'])->whereIn('book', LegalBookService::Books)->name('books.csv');
        Route::get('/books/{book}/pdf', [LegalBookController::class, 'printable'])->whereIn('book', LegalBookService::Books)->name('books.pdf');
        Route::get('/archive', [LegalBookController::class, 'archive'])->name('archive');
        Route::get('/protocol', [LegalBookController::class, 'protocol'])->name('protocol.index');
        Route::post('/protocol', [LegalBookController::class, 'register'])->name('protocol.store');
        Route::post('/protocol/{id}/cancel', [LegalBookController::class, 'cancel'])->name('protocol.cancel');
        Route::get('/protocol/{id}/download', [LegalBookController::class, 'download'])->name('protocol.download');

        // Volunteers, shifts and reimbursements
        Route::get('/volunteers/year/{year}', [VolunteerController::class, 'ofYear'])->where('year', '[0-9]{4}')->name('volunteers.year');
        Route::get('/volunteers/expiries', [VolunteerController::class, 'expiries'])->name('volunteers.expiries');
        Route::get('/shifts/attention', [VolunteerController::class, 'attention'])->name('shifts.attention');
        Route::post('/shifts/{id}/availability', [VolunteerController::class, 'availability'])->name('shifts.availability');
        Route::post('/shifts/{id}/assignments', [VolunteerController::class, 'assign'])->name('shifts.assignments.store');
        Route::get('/shifts/{id}/coverage', [VolunteerController::class, 'coverage'])->name('shifts.coverage');
        Route::post('/shift-assignments/{id}/substitution-request', [VolunteerController::class, 'requestSubstitution'])->name('shift-assignments.substitution-request');
        Route::post('/shift-assignments/{id}/substitute', [VolunteerController::class, 'substitute'])->name('shift-assignments.substitute');
        Route::get('/reimbursements', [ReimbursementController::class, 'index'])->name('reimbursements.index');
        Route::post('/reimbursements', [ReimbursementController::class, 'store'])->name('reimbursements.store');
        Route::post('/reimbursements/{id}/attachments', [ReimbursementController::class, 'attach'])->name('reimbursements.attachments.store');
        Route::get('/reimbursement-attachments/{id}/download', [ReimbursementController::class, 'download'])->name('reimbursement-attachments.download');
        Route::post('/reimbursements/{id}/decision', [ReimbursementController::class, 'decide'])->name('reimbursements.decision');
        Route::post('/reimbursements/{id}/pay', [ReimbursementController::class, 'pay'])->name('reimbursements.pay');

        // Data protection
        Route::get('/privacy/activities', [PrivacyController::class, 'activities'])->name('privacy.activities.index');
        Route::post('/privacy/activities', [PrivacyController::class, 'storeActivity'])->name('privacy.activities.store');
        Route::put('/privacy/activities/{id}', [PrivacyController::class, 'updateActivity'])->name('privacy.activities.update');
        Route::post('/privacy/activities/{id}/finalize', [PrivacyController::class, 'finalize'])->name('privacy.activities.finalize');
        Route::post('/privacy/activities/{id}/versions', [PrivacyController::class, 'newVersion'])->name('privacy.activities.versions');
        Route::get('/privacy/activities/{id}/notice', [PrivacyController::class, 'notice'])->name('privacy.activities.notice');
        Route::get('/privacy/activities/{id}/notice/pdf', [PrivacyController::class, 'noticePdf'])->name('privacy.activities.notice.pdf');
        Route::get('/privacy/register', [PrivacyController::class, 'register'])->name('privacy.register');
        Route::get('/privacy/register/csv', [PrivacyController::class, 'registerCsv'])->name('privacy.register.csv');
        Route::get('/privacy/register/pdf', [PrivacyController::class, 'registerPdf'])->name('privacy.register.pdf');
        Route::get('/privacy/subjects/{id}/appointment', [PrivacyController::class, 'appointment'])->name('privacy.subjects.appointment');
        Route::get('/privacy/consents', [PrivacyController::class, 'consents'])->name('privacy.consents.index');
        Route::post('/privacy/consents', [PrivacyController::class, 'recordConsent'])->name('privacy.consents.store');
        Route::post('/privacy/consents/{id}/withdraw', [PrivacyController::class, 'withdrawConsent'])->name('privacy.consents.withdraw');
        Route::get('/privacy/requests', [PrivacyController::class, 'requests'])->name('privacy.requests.index');
        Route::post('/privacy/requests', [PrivacyController::class, 'receive'])->name('privacy.requests.store');
        Route::post('/privacy/requests/{id}/extend', [PrivacyController::class, 'extend'])->name('privacy.requests.extend');
        Route::get('/privacy/requests/{id}/erasure-preview', [PrivacyController::class, 'erasurePreview'])->name('privacy.requests.erasure-preview');
        Route::post('/privacy/requests/{id}/complete', [PrivacyController::class, 'complete'])->name('privacy.requests.complete');
        Route::post('/privacy/requests/{id}/reject', [PrivacyController::class, 'reject'])->name('privacy.requests.reject');
        Route::get('/privacy/requests/{id}/export', [PrivacyController::class, 'export'])->name('privacy.requests.export');

        // Donations, campaigns, 5x1000 and sponsors
        Route::get('/donations', [FundraisingController::class, 'donations'])->name('donations.index');
        Route::post('/donations', [FundraisingController::class, 'recordDonation'])->name('donations.store');
        Route::post('/donations/{id}/receipt', [FundraisingController::class, 'issueReceipt'])->name('donations.receipt');
        Route::post('/donation-receipts/{id}/cancel', [FundraisingController::class, 'cancelReceipt'])->name('donation-receipts.cancel');
        Route::get('/donation-receipts/{id}/pdf', [FundraisingController::class, 'receiptPdf'])->name('donation-receipts.pdf');
        Route::post('/campaigns/{id}/expenses', [FundraisingController::class, 'campaignExpense'])->name('campaigns.expenses.store');
        Route::get('/campaigns/{id}/report', [FundraisingController::class, 'campaignReport'])->name('campaigns.report');
        Route::get('/five-per-mille', [FundraisingController::class, 'fivePerMille'])->name('five-per-mille.index');
        Route::post('/five-per-mille', [FundraisingController::class, 'recordFivePerMille'])->name('five-per-mille.store');
        Route::post('/five-per-mille/{id}/expenses', [FundraisingController::class, 'fivePerMilleExpense'])->name('five-per-mille.expenses.store');
        Route::get('/five-per-mille/{id}/report', [FundraisingController::class, 'fivePerMilleReport'])->name('five-per-mille.report');
        Route::get('/five-per-mille/{id}/csv', [FundraisingController::class, 'fivePerMilleCsv'])->name('five-per-mille.csv');
        Route::post('/sponsor-contracts/{id}/document', [FundraisingController::class, 'attachContract'])->name('sponsor-contracts.document.store');
        Route::get('/sponsor-contracts/{id}/document', [FundraisingController::class, 'downloadContract'])->name('sponsor-contracts.document');

        // Third-sector statements
        Route::get('/statements/schemes', [StatementController::class, 'schemes'])->name('statements.schemes');
        Route::get('/statements/mappings', [StatementController::class, 'mappings'])->name('statements.mappings.index');
        Route::post('/statements/mappings', [StatementController::class, 'storeMapping'])->name('statements.mappings.store');
        Route::put('/statements/mappings/{id}', [StatementController::class, 'updateMapping'])->name('statements.mappings.update');
        Route::delete('/statements/mappings/{id}', [StatementController::class, 'destroyMapping'])->name('statements.mappings.destroy');
        Route::get('/statements/filings', [StatementController::class, 'filingsIndex'])->name('statements.filings.index');
        Route::get('/statements/{year}/management-report', [StatementController::class, 'managementReport'])->where('year', '[0-9]{4}')->name('statements.management-report');
        Route::get('/statements/{year}/balance-sheet', [StatementController::class, 'balanceSheet'])->where('year', '[0-9]{4}')->name('statements.balance-sheet');
        Route::get('/statements/{year}/cash-report', [StatementController::class, 'cashReport'])->where('year', '[0-9]{4}')->name('statements.cash-report');
        Route::get('/statements/{year}/checks', [StatementController::class, 'checks'])->where('year', '[0-9]{4}')->name('statements.checks');
        Route::get('/statements/{year}/mission-report', [StatementController::class, 'missionReport'])->where('year', '[0-9]{4}')->name('statements.mission-report');
        Route::put('/statements/{year}/mission-report', [StatementController::class, 'saveMissionReport'])->where('year', '[0-9]{4}')->name('statements.mission-report.save');
        Route::post('/statements/{year}/filings', [StatementController::class, 'prepare'])->where('year', '[0-9]{4}')->name('statements.filings.store');
        Route::post('/statement-filings/{id}/filed', [StatementController::class, 'markFiled'])->name('statement-filings.filed');
        Route::get('/statement-filings/{id}/csv', [StatementController::class, 'csv'])->name('statement-filings.csv');
        Route::get('/statement-filings/{id}/pdf', [StatementController::class, 'printable'])->name('statement-filings.pdf');

        // Law 398/1991
        Route::get('/lex398/{year}/monitor', [Lex398Controller::class, 'monitor'])->where('year', '[0-9]{4}')->name('lex398.monitor');
        Route::get('/lex398/{year}/register', [Lex398Controller::class, 'register'])->where('year', '[0-9]{4}')->name('lex398.register');
        Route::get('/lex398/{year}/register/csv', [Lex398Controller::class, 'csv'])->where('year', '[0-9]{4}')->name('lex398.register.csv');
        Route::post('/lex398/entries', [Lex398Controller::class, 'record'])->name('lex398.entries.store');
        Route::post('/lex398/{year}/sync-invoices', [Lex398Controller::class, 'sync'])->where('year', '[0-9]{4}')->name('lex398.sync');

        // Sports work
        Route::get('/sport/compensations', [SportWorkController::class, 'compensations'])->name('sport.compensations.index');
        Route::post('/sport/compensations', [SportWorkController::class, 'record'])->name('sport.compensations.store');
        Route::get('/sport/expiries', [SportWorkController::class, 'expiries'])->name('sport.expiries');
        Route::get('/sport/{year}/thresholds', [SportWorkController::class, 'thresholds'])->where('year', '[0-9]{4}')->name('sport.thresholds');
        Route::get('/sport/{year}/single-book', [SportWorkController::class, 'singleBook'])->where('year', '[0-9]{4}')->name('sport.single-book');
        Route::get('/sport/{year}/single-book/csv', [SportWorkController::class, 'singleBookCsv'])->where('year', '[0-9]{4}')->name('sport.single-book.csv');
        Route::get('/sport/{year}/accountant-csv', [SportWorkController::class, 'accountantCsv'])->where('year', '[0-9]{4}')->name('sport.accountant-csv');
        Route::get('/sport/{year}/registry-csv', [SportWorkController::class, 'registryCsv'])->where('year', '[0-9]{4}')->name('sport.registry-csv');
        Route::post('/sport-contracts/{id}/document', [SportWorkController::class, 'attach'])->name('sport-contracts.document.store');
        Route::get('/sport-contracts/{id}/document', [SportWorkController::class, 'download'])->name('sport-contracts.document');

        // Sections and the network of affiliates
        Route::get('/sections/{year}/report', [SectionController::class, 'report'])->where('year', '[0-9]{4}')->name('sections.report');
        Route::get('/network/statements/{year}', [NetworkController::class, 'statements'])->where('year', '[0-9]{4}')->name('network.statements');
    });

// Public online enrolment: the token of the link selects the tenant.
Route::prefix('api/v1/association-public/enrolment/{token}')
    ->middleware(['api', 'locale', 'throttle:association-public', ResolveEnrolmentForm::class])
    ->name('api.v1.association-public.')
    ->where(['token' => '[A-Za-z0-9]{48}'])
    ->group(function (): void {
        Route::get('/', [PublicEnrolmentController::class, 'show'])->name('show');
        Route::post('/', [PublicEnrolmentController::class, 'submit'])->middleware('throttle:association-enrolment')->name('submit');
        Route::get('/documents/{documentId}', [PublicEnrolmentController::class, 'document'])->name('documents');
        Route::get('/applications/{trackingCode}', [PublicEnrolmentController::class, 'track'])->name('track');
    });

// Readers: the secret of the device selects the tenant.
Route::post('api/v1/association-access/verify', AccessVerifyController::class)
    ->middleware(['api', 'throttle:association-reader', AuthenticateAccessDevice::class])
    ->name('api.v1.association-access.verify');

// Online payment provider: the signature of the driver authenticates the call.
Route::post('api/v1/association-payments/callback', PaymentCallbackController::class)
    ->middleware(['api', 'throttle:association-payment'])
    ->name('api.v1.association-payments.callback');

// Member area: the personal token selects the tenant and the person.
Route::prefix('api/v1/association-area')
    ->middleware(['api', 'locale', 'throttle:association-area', AuthenticateMemberArea::class])
    ->name('api.v1.association-area.')
    ->where(['id' => $uuid, 'memberId' => $uuid])
    ->group(function (): void {
        Route::get('/me', [MemberAreaController::class, 'me'])->name('me');
        Route::put('/me/contact', [MemberAreaController::class, 'updateContact'])->name('me.contact');
        Route::put('/members/{id}/contact', [MemberAreaController::class, 'updateWardContact'])->name('members.contact');
        Route::get('/members/{id}/card', [MemberAreaController::class, 'card'])->name('members.card');
        Route::get('/members/{id}/card/pdf', [MemberAreaController::class, 'cardPdf'])->name('members.card.pdf');
        Route::get('/members/{id}/fees', [MemberAreaController::class, 'fees'])->name('members.fees');
        Route::post('/instalments/{id}/pay', [MemberAreaController::class, 'pay'])->name('instalments.pay');
        Route::get('/members/{id}/receipts', [MemberAreaController::class, 'receiptList'])->name('members.receipts');
        Route::get('/receipts/{id}/pdf', [MemberAreaController::class, 'receiptPdf'])->name('receipts.pdf');
        Route::get('/members/{id}/calendar', [MemberAreaController::class, 'memberCalendar'])->name('members.calendar');
        Route::get('/members/{id}/documents', [MemberAreaController::class, 'documents'])->name('members.documents');
        Route::get('/members/{memberId}/documents/{id}/download', [MemberAreaController::class, 'downloadDocument'])->name('members.documents.download');
        Route::get('/members/{id}/authorizations', [MemberAreaController::class, 'authorizations'])->name('members.authorizations');
        Route::post('/members/{memberId}/authorizations/{id}/sign', [MemberAreaController::class, 'signAuthorization'])->name('members.authorizations.sign');
        Route::get('/instructor/courses', [MemberAreaController::class, 'instructorCourses'])->name('instructor.courses');
        Route::get('/instructor/courses/{id}/participants', [MemberAreaController::class, 'instructorParticipants'])->name('instructor.participants');
        Route::post('/instructor/courses/{id}/attendance', [MemberAreaController::class, 'instructorAttendance'])->name('instructor.attendance');
        Route::get('/ballots', [BallotController::class, 'areaIndex'])->name('ballots.index');
        Route::post('/ballots/{id}/vote', [BallotController::class, 'areaVote'])->name('ballots.vote');
    });
