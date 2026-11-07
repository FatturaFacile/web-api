<?php

declare(strict_types=1);

use App\Services\Backup\TenantBackupService;
use App\Services\Backup\TenantRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AccessLog;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\MedicalCertificate;
use Modules\Associations\Models\Member;
use Modules\Associations\Services\CertificateService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    Storage::fake('private');
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
});

afterEach(fn () => associationsTearDown());

test('a tenant backup carries the registry, the private files and the access log, and a restore brings them back', function (): void {
    $minor = Member::factory()->minor()->create(['health_notes' => 'asthma']);
    $guardian = Member::factory()->create();
    Guardianship::query()->create(['minor_member_id' => $minor->id, 'guardian_contact_id' => $guardian->contact_id, 'is_primary' => true]);
    $certificate = app(CertificateService::class)->store($minor, ['kind' => CertificateKind::Competitive->value, 'expires_on' => now()->addYear()->toDateString()], UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'));
    $area = AccessArea::factory()->create();
    $log = AccessLog::query()->create([
        'device_id' => (string) Str::uuid7(), 'area_id' => $area->id, 'direction' => 'in', 'decision' => 'deny', 'reason_code' => 'unknown_credential', 'occurred_at' => now(),
    ]);

    expect($certificate->path)->toStartWith("tenants/{$this->tenant->id}/associations/");

    $backups = app(TenantBackupService::class);
    $backup = $backups->generate($backups->create($this->tenant))->refresh();

    Member::withoutGlobalScopes()->whereKey($minor->id)->update(['status' => 'ceased']);
    Storage::disk('local')->delete($certificate->path);

    $summary = app(TenantRestoreService::class)->restoreBackup($backup, $this->tenant);

    expect($summary['skipped_rows'])->toBe(0)
        ->and(Member::withoutGlobalScopes()->findOrFail($minor->id)->health_notes)->toBe('asthma')
        ->and(Member::withoutGlobalScopes()->findOrFail($minor->id)->status->value)->toBe('active')
        ->and(MedicalCertificate::withoutGlobalScopes()->whereKey($certificate->id)->exists())->toBeTrue()
        ->and(DB::table('assoc_access_logs')->where('id', $log->id)->count())->toBe(1);

    Storage::disk('local')->assertExists($certificate->path);
});
