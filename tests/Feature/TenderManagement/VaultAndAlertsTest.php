<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderAlert;

require_once __DIR__.'/TenderManagementSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    tndSetUp($this);
    Storage::fake('local');
});

it('stores an SOA attestation with its categories, the scanned file and the triennial verification date', function (): void {
    $response = $this->post('/api/v1/tender-management/vault/documents', [
        'doc_type' => 'IT_SOA_Attestation',
        'document_number' => 'SOA-1',
        'issue_date' => '2025-01-10',
        'soa_categories_classifications' => json_encode([['category' => 'OG1', 'classification' => 'III'], ['category' => 'OS28', 'classification' => 'II']]),
        'file' => UploadedFile::fake()->create('soa.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $response->assertJsonPath('data.jurisdiction', 'IT')
        ->assertJsonPath('data.has_file', true)
        ->assertJsonPath('data.intermediate_triennial_check_date', '2028-01-10')
        ->assertJsonPath('data.effective_expiry_date', '2030-01-10')
        ->assertJsonPath('data.soa_categories_classifications.1.category', 'OS28');
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('rejects an SOA classification that does not exist', function (): void {
    tndAddVaultDocument($this, 'IT_SOA_Attestation', ['soa_categories_classifications' => [['category' => 'OG99', 'classification' => 'III']]])->assertUnprocessable();
});

it('shows the traffic light of every document and what is missing, split by Italy and Switzerland', function (): void {
    tndAddVaultDocument($this, 'IT_DURC')->assertCreated();
    tndAddVaultDocument($this, 'IT_VisuraCamerale', ['expiry_date' => now()->subDay()->toDateString(), 'issue_date' => now()->subMonths(2)->toDateString()])->assertCreated();
    tndAddVaultDocument($this, 'CH_AttestatoSUVA', ['expiry_date' => now()->addDays(20)->toDateString()])->assertCreated();
    tndAddVaultDocument($this, 'CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug', ['issue_date' => now()->subDays(10)->toDateString(), 'expiry_date' => null])->assertCreated();

    $response = $this->getJson('/api/v1/tender-management/vault/overview')->assertOk();

    expect($response->json('data.it.summary'))->toBe(['Valid' => 1, 'ExpiringSoon' => 0, 'Expired' => 1, 'PendingRenewal' => 0])
        ->and($response->json('data.it.missing_types'))->toContain('IT_SOA_Attestation', 'IT_VisuraCamerale', 'IT_CasellarioGiudiziale_CarichiPendenti')
        ->and($response->json('data.ch.summary.ExpiringSoon'))->toBe(1)
        ->and($response->json('data.ch.missing_types'))->toContain('CH_AttestatoAVS_AI_IPG')->not->toContain('CH_AttestatoSUVA');

    $this->getJson('/api/v1/tender-management/vault/documents?jurisdiction=CH')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/tender-management/vault/documents?verification_status=Expired')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.doc_type', 'IT_VisuraCamerale');
});

it('treats a Swiss debt-enforcement extract older than three months as expired', function (): void {
    tndAddVaultDocument($this, 'CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug', ['issue_date' => now()->subMonths(4)->toDateString(), 'expiry_date' => null])
        ->assertCreated()
        ->assertJsonPath('data.verification_status', 'Expired');
});

it('renews a document so that the new one replaces the old one in the vault', function (): void {
    $old = tndAddVaultDocument($this, 'IT_DURC', ['document_number' => 'DURC-OLD'])->assertCreated()->json('data.id');

    $new = $this->postJson("/api/v1/tender-management/vault/documents/{$old}/renew", ['document_number' => 'DURC-NEW', 'issue_date' => now()->toDateString(), 'expiry_date' => now()->addMonths(4)->toDateString()])
        ->assertCreated()
        ->assertJsonPath('data.renewed_from_id', $old)
        ->assertJsonPath('data.doc_type', 'IT_DURC')
        ->json('data.id');

    $listed = $this->getJson('/api/v1/tender-management/vault/documents?doc_type=IT_DURC')->assertOk();

    expect(array_column($listed->json('data'), 'id'))->toBe([$new]);
});

it('flags a SOA or DURC as aligned with FVOE 2.0 and refuses other documents', function (): void {
    $soa = tndAddVaultDocument($this, 'IT_SOA_Attestation')->assertCreated()->json('data.id');
    $iso = tndAddVaultDocument($this, 'IT_ISO9001')->assertCreated()->json('data.id');

    $this->postJson("/api/v1/tender-management/vault/documents/{$soa}/sync-fvoe")->assertOk()->assertJsonPath('data.fvoe20_synced', true);
    $this->postJson("/api/v1/tender-management/vault/documents/{$iso}/sync-fvoe")->assertUnprocessable()->assertJsonPath('code', 'document_type_jurisdiction_mismatch');
    $this->getJson('/api/v1/tender-management/vault/overview')->assertJsonPath('data.fvoe.synced', 1)->assertJsonPath('data.fvoe.total', 1);
});

it('updates and deletes a document together with its file', function (): void {
    $id = tndAddVaultDocument($this, 'IT_ISO9001')->assertCreated()->json('data.id');

    $this->putJson("/api/v1/tender-management/vault/documents/{$id}", ['title' => 'ISO 9001:2015'])->assertOk()->assertJsonPath('data.title', 'ISO 9001:2015');
    $this->deleteJson("/api/v1/tender-management/vault/documents/{$id}")->assertOk();
    $this->getJson("/api/v1/tender-management/vault/documents/{$id}")->assertNotFound();
});

it('raises the tightest deadline milestone once, then the next one as the deadline approaches', function (): void {
    $noticeId = tndCreateNotice($this, ['clarification_deadline_at' => null, 'submission_peremptory_deadline_at' => '2027-03-10 12:00:00']);
    tndCreateParticipation($this, $noticeId);

    Artisan::call('tender:scan-alerts', ['--at' => '2027-03-07 20:00:00']);
    Artisan::call('tender:scan-alerts', ['--at' => '2027-03-07 21:00:00']);

    expect(TenderAlert::query()->where('alert_type', 'submission_deadline')->pluck('milestone')->all())->toBe(['-72h']);

    Artisan::call('tender:scan-alerts', ['--at' => '2027-03-10 09:00:00']);

    $alerts = TenderAlert::query()->where('alert_type', 'submission_deadline')->orderBy('milestone')->get();
    expect($alerts->pluck('milestone')->all())->toBe(['-4h', '-72h'])
        ->and($alerts->firstWhere('milestone', '-4h')->severity->value)->toBe('critical')
        ->and($alerts->first()->message_key)->toBe('tenderManagement.alert.submission_deadline');
});

it('stays quiet for a dossier that was already submitted and after the deadline', function (): void {
    $noticeId = tndCreateNotice($this, ['clarification_deadline_at' => null, 'submission_peremptory_deadline_at' => '2027-03-10 12:00:00']);
    $id = tndCreateParticipation($this, $noticeId);
    tndForceStatus($id, 'Submitted');

    Artisan::call('tender:scan-alerts', ['--at' => '2027-03-10 09:00:00']);

    expect(TenderAlert::query()->count())->toBe(0);
});

it('raises the vault expiry, the rolling three month and the SOA verification alerts', function (): void {
    tndAddVaultDocument($this, 'CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug', ['issue_date' => '2027-01-05', 'expiry_date' => null])->assertCreated();
    tndAddVaultDocument($this, 'IT_DURC', ['issue_date' => '2027-02-01', 'expiry_date' => '2027-04-02'])->assertCreated();
    tndAddVaultDocument($this, 'IT_SOA_Attestation', ['issue_date' => '2024-03-25', 'expiry_date' => '2029-03-25', 'intermediate_triennial_check_date' => '2027-03-25'])->assertCreated();

    Artisan::call('tender:scan-alerts', ['--at' => '2027-03-20 08:00:00']);

    $byType = TenderAlert::query()->get()->groupBy('alert_type')->map(fn ($group) => $group->pluck('milestone')->all())->all();

    // The extract lapses on 2027-04-05 (16 days left), the DURC on 2027-04-02 (13 days), the SOA verification falls on 2027-03-25 (5 days).
    expect($byType['betreibungsauszug_age'])->toBe(['30d'])
        ->and($byType['vault_expiry'])->toBe(['30d'])
        ->and($byType['soa_milestone'])->toBe(['30d']);

    Artisan::call('tender:scan-alerts', ['--at' => '2027-04-03 08:00:00']);

    // Two days before the extract lapses the 7 day milestone is the tightest one reached; the DURC lapsed yesterday.
    expect(TenderAlert::query()->where('alert_type', 'betreibungsauszug_age')->pluck('milestone')->sort()->values()->all())->toBe(['30d', '7d'])
        ->and(TenderAlert::query()->where('alert_type', 'vault_expiry')->pluck('milestone')->sort()->values()->all())->toBe(['0d', '30d'])
        ->and(QualificationVaultDocument::query()->where('doc_type', 'IT_DURC')->first()->verification_status->value)->toBe('Expired');
});

it('lists the alerts and lets the user acknowledge them', function (): void {
    $noticeId = tndCreateNotice($this, ['clarification_deadline_at' => null, 'submission_peremptory_deadline_at' => now()->addHours(3)->toDateTimeString()]);
    tndCreateParticipation($this, $noticeId);
    Artisan::call('tender:scan-alerts');

    $id = $this->getJson('/api/v1/tender-management/alerts?acknowledged=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.milestone', '-4h')->json('data.0.id');
    $this->postJson("/api/v1/tender-management/alerts/{$id}/acknowledge")->assertOk();

    $this->getJson('/api/v1/tender-management/alerts?acknowledged=0')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/tender-management/alerts?acknowledged=1')->assertOk()->assertJsonCount(1, 'data');
});
