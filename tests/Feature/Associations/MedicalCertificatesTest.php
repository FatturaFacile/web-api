<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Associations\Enums\AccessReason;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Mail\AssociationNoticeMail;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\Discipline;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\MedicalCertificate;
use Modules\Associations\Models\Member;
use Modules\Associations\Services\CertificateService;
use Modules\Associations\Services\ReminderService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    actAs($this, 'secretary');
    Storage::fake('private');
    Mail::fake();
    config(['filesystems.private_disk' => 'private']);
});

afterEach(fn () => associationsTearDown());

test('the scan goes to the private disk, and only authorized roles reach it', function (): void {
    $member = Member::factory()->create();

    $id = $this->post("/api/v1/associations/members/{$member->id}/certificates", [
        'kind' => 'competitive',
        'expires_on' => now()->addYear()->toDateString(),
        'physician' => 'Dr. Bruni',
        'file' => UploadedFile::fake()->create('certificate.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('data.path')->json('data.id');

    $row = DB::table('assoc_medical_certificates')->where('id', $id)->first();

    expect($row->disk)->toBe('private')
        ->and($row->path)->toStartWith("tenants/{$this->tenant->id}/associations/certificates/")
        ->and($row->physician)->not->toContain('Bruni');

    Storage::disk('private')->assertExists($row->path);

    $this->get("/api/v1/associations/certificates/{$id}/download")->assertOk();

    foreach (['accountant', 'member', 'instructor'] as $role) {
        actAs($this, $role);
        $this->getJson("/api/v1/associations/certificates/{$id}/download")->assertForbidden();
    }
});

test('a scan of another tenant is not reachable', function (): void {
    $foreign = inTenant($this->otherTenant, fn () => MedicalCertificate::factory()->create(['member_id' => Member::factory()->create()->id]));

    $this->getJson("/api/v1/associations/certificates/{$foreign->id}/download")->assertNotFound();
});

test('expired and expiring lists follow the alert days, by default 30', function (): void {
    $expired = MedicalCertificate::factory()->create(['member_id' => Member::factory()->create()->id, 'expires_on' => now()->subDay()]);
    $soon = MedicalCertificate::factory()->create(['member_id' => Member::factory()->create()->id, 'expires_on' => now()->addDays(29)]);
    $later = MedicalCertificate::factory()->create(['member_id' => Member::factory()->create()->id, 'expires_on' => now()->addDays(40)]);

    $ids = fn (string $list): array => collect($this->getJson("/api/v1/associations/certificates/{$list}")->assertOk()->json('data'))->pluck('id')->all();

    expect(AssociationSetting::current()->certificate_alert_days)->toBe(30)
        ->and($ids('expired'))->toBe([$expired->id])
        ->and($ids('expiring'))->toBe([$soon->id]);

    AssociationSetting::current()->update(['certificate_alert_days' => 45]);

    expect($ids('expiring'))->toBe([$soon->id, $later->id]);
});

test('a renewed certificate takes the old one off the lists', function (): void {
    $member = Member::factory()->create();
    MedicalCertificate::factory()->create(['member_id' => $member->id, 'expires_on' => now()->subDay()]);
    MedicalCertificate::factory()->create(['member_id' => $member->id, 'expires_on' => now()->addYear()]);

    expect($this->getJson('/api/v1/associations/certificates/expired')->json('data'))->toBe([]);
});

test('what a certificate covers depends on kind, discipline and date', function (): void {
    $football = Discipline::query()->create(['code' => 'CAL', 'name' => 'Football']);
    $swimming = Discipline::query()->create(['code' => 'NUO', 'name' => 'Swimming']);
    $member = Member::factory()->create();
    $certificates = app(CertificateService::class);

    expect($certificates->problem($member, $football->id, CertificateKind::NonCompetitive))->toBe(AccessReason::CertificateMissing);

    MedicalCertificate::factory()->create(['member_id' => $member->id, 'kind' => CertificateKind::Competitive, 'discipline_id' => $football->id, 'expires_on' => now()]);

    expect($certificates->problem($member, $football->id, CertificateKind::Competitive))->toBeNull()
        ->and($certificates->problem($member, $football->id, CertificateKind::NonCompetitive))->toBeNull()
        ->and($certificates->problem($member, $swimming->id, CertificateKind::NonCompetitive))->toBe(AccessReason::CertificateMissing)
        ->and($certificates->problem($member, $football->id, CertificateKind::Competitive, now()->addDay()->toImmutable()))->toBe(AccessReason::CertificateExpired);
});

test('a withdrawn certificate stops counting', function (): void {
    $member = Member::factory()->create();
    $certificate = MedicalCertificate::factory()->create(['member_id' => $member->id]);

    $this->deleteJson("/api/v1/associations/certificates/{$certificate->id}")->assertNoContent();

    expect(app(CertificateService::class)->problem($member, null, CertificateKind::NonCompetitive))->toBe(AccessReason::CertificateMissing);
});

test('alerts go out once, to the guardian of a minor', function (): void {
    $minor = Member::factory()->minor()->create();
    $guardian = Contact::create(['first_name' => 'Paola', 'last_name' => 'Neri', 'email' => 'paola@example.com']);
    Guardianship::query()->create(['minor_member_id' => $minor->id, 'guardian_contact_id' => $guardian->id, 'is_primary' => true]);
    MedicalCertificate::factory()->create(['member_id' => $minor->id, 'expires_on' => now()->addDays(10)]);
    $reminders = app(ReminderService::class);

    expect($reminders->sendCertificateAlerts())->toBe(1)
        ->and($reminders->sendCertificateAlerts())->toBe(0);

    Mail::assertSent(AssociationNoticeMail::class, fn (AssociationNoticeMail $mail): bool => $mail->hasTo('paola@example.com'));
});
