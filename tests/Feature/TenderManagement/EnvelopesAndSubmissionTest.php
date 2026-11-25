<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\TenderManagement\Models\TenderNotice;

require_once __DIR__.'/TenderManagementSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    tndSetUp($this);
    Storage::fake('local');
});

it('refuses an unsigned file for a document that must be signed', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/envelopes/economic/documents", ['doc_key' => 'economic_offer', 'file' => UploadedFile::fake()->createWithContent('offer.txt', 'plain')])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_signature_format');
});

it('validates the .p7m signature of an envelope document and marks the item ok', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/envelopes/economic/documents", ['doc_key' => 'economic_offer', 'file' => tndP7m()])
        ->assertCreated()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.file_name', 'offer.pdf.p7m')
        ->assertJsonPath('data.signature.format', 'p7m')
        ->assertJsonPath('data.signature.status', 'valid')
        ->assertJsonMissingPath('data.path');
});

it('flags a corrupt .p7m as an invalid signature without accepting the item', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/envelopes/economic/documents", ['doc_key' => 'economic_offer', 'file' => UploadedFile::fake()->createWithContent('offer.p7m', 'broken')])
        ->assertCreated()
        ->assertJsonPath('data.status', 'signature_invalid')
        ->assertJsonPath('data.signature.status', 'invalid');
});

it('keeps the envelopes separate: a document belongs to the envelope that lists it', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/envelopes/technical/documents", ['doc_key' => 'economic_offer', 'file' => tndP7m()])
        ->assertNotFound()
        ->assertJsonPath('code', 'unknown_envelope_document');
    $this->postJson("/api/v1/tender-management/participations/{$id}/envelopes/secret/documents", ['doc_key' => 'x', 'file' => tndP7m()])
        ->assertNotFound()
        ->assertJsonPath('code', 'unknown_envelope');
});

it('removes an uploaded file and puts the item back to missing', function (): void {
    $id = tndCreateParticipation($this);
    $this->postJson("/api/v1/tender-management/participations/{$id}/envelopes/technical/documents", ['doc_key' => 'technical_offer', 'file' => tndP7m('tech.p7m')])->assertCreated();

    $this->deleteJson("/api/v1/tender-management/participations/{$id}/envelopes/technical/documents/technical_offer")->assertOk();

    $this->getJson("/api/v1/tender-management/participations/{$id}")->assertOk()->assertJsonPath('data.technical_envelope_docs.0.status', 'missing');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('redefines the checklist of an envelope keeping the files already uploaded', function (): void {
    $id = tndCreateParticipation($this);
    $this->postJson("/api/v1/tender-management/participations/{$id}/envelopes/technical/documents", ['doc_key' => 'technical_offer', 'file' => tndP7m('tech.p7m')])->assertCreated();

    $this->putJson("/api/v1/tender-management/participations/{$id}/envelopes/technical", ['items' => [
        ['key' => 'technical_offer', 'required' => true, 'signature_required' => true],
        ['key' => 'site_visit_certificate', 'label' => 'Site visit', 'required' => false],
    ]])->assertOk()
        ->assertJsonCount(2, 'data.technical_envelope_docs')
        ->assertJsonPath('data.technical_envelope_docs.0.status', 'ok')
        ->assertJsonPath('data.technical_envelope_docs.1.label', 'Site visit');
});

it('reports what stands between the dossier and its submission', function (): void {
    $id = tndCreateParticipation($this);

    $response = $this->getJson("/api/v1/tender-management/participations/{$id}/readiness")->assertOk()->assertJsonPath('data.ready', false);
    $codes = array_column($response->json('data.issues'), 'code');

    expect($codes)->toContain('document_missing', 'edgue_missing', 'bid_bond_missing', 'art108_safety_costs_missing')
        ->and($response->json('data.envelopes.economic.complete'))->toBeFalse()
        ->and(array_column($response->json('data.issues'), 'envelope'))->toContain('administrative', 'economic');
});

it('generates the eDGUE of an Italian tender as data, XML and a download', function (): void {
    $id = tndCreateParticipation($this);

    $this->getJson("/api/v1/tender-management/participations/{$id}/edgue")
        ->assertOk()
        ->assertJsonPath('data.format', 'edgue')
        ->assertJsonPath('data.data.procedure.cig', 'ABC1234567')
        ->assertJsonPath('data.data.economic_operator.vat_number', 'IT01234567890')
        ->assertJsonPath('data.data.exclusion_grounds.0.requires_operator_confirmation', true);

    $this->getJson("/api/v1/tender-management/participations/{$id}")->assertJsonPath('data.has_edgue', true);

    $xml = $this->get("/api/v1/tender-management/participations/{$id}/edgue/download?format=xml");
    $xml->assertOk()->assertHeader('Content-Type', 'application/xml');
    expect($xml->getContent())->toContain('<espd:ESPDResponse')->toContain('ABC1234567');
});

it('offers no eDGUE for a Swiss tender', function (): void {
    $noticeId = tndCreateNotice($this, ['jurisdiction' => 'CH_SIMAP_LAPub', 'currency' => 'CHF', 'award_criterion' => 'LowestPrice', 'formula_type' => null, 'simap_project_number' => '77001']);
    $id = tndCreateParticipation($this, $noticeId);

    $this->getJson("/api/v1/tender-management/participations/{$id}/edgue")->assertUnprocessable()->assertJsonPath('code', 'edgue_unsupported_jurisdiction');
});

it('lodges a private tender once every envelope document is in and the dossier is ready', function (): void {
    $noticeId = tndCreateNotice($this, ['jurisdiction' => 'PrivateRfp', 'award_criterion' => 'LowestPrice', 'formula_type' => null, 'cig_code_it' => null, 'cup_code_it' => null]);
    $id = tndCreateParticipation($this, $noticeId);

    $this->postJson("/api/v1/tender-management/participations/{$id}/transition", ['status' => 'GoApproved'])->assertOk();
    $this->postJson("/api/v1/tender-management/participations/{$id}/transition", ['status' => 'PreparingEnvelopes'])->assertOk();
    $this->postJson("/api/v1/tender-management/participations/{$id}/submit")->assertUnprocessable()->assertJsonPath('code', 'readiness_blocked');

    $upload = fn (string $envelope, string $key, UploadedFile $file) => $this->postJson("/api/v1/tender-management/participations/{$id}/envelopes/{$envelope}/documents", ['doc_key' => $key, 'file' => $file])->assertCreated();
    $upload('administrative', 'company_profile', tndPlainFile('profile.pdf'));
    $upload('administrative', 'declarations', tndP7m('declarations.p7m'));
    $upload('technical', 'technical_offer', tndPlainFile('technical.pdf'));
    $upload('economic', 'economic_offer', tndP7m('economic.p7m'));

    $this->getJson("/api/v1/tender-management/participations/{$id}/readiness")->assertOk()->assertJsonPath('data.ready', true);

    $this->postJson("/api/v1/tender-management/participations/{$id}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'Submitted')
        ->assertJsonPath('data.allowed_transitions', ['AwardedWon', 'Lost']);

    $this->postJson("/api/v1/tender-management/participations/{$id}/envelopes/economic/documents", ['doc_key' => 'economic_offer', 'file' => tndP7m('economic.p7m')])->assertUnprocessable();
});

it('refuses to submit after the peremptory deadline', function (): void {
    $noticeId = tndCreateNotice($this, ['jurisdiction' => 'PrivateRfp', 'award_criterion' => 'LowestPrice', 'formula_type' => null]);
    $id = tndCreateParticipation($this, $noticeId);
    tndForceStatus($id, 'PreparingEnvelopes');
    TenderNotice::query()->whereKey($noticeId)->update(['submission_peremptory_deadline_at' => now()->subHour()]);

    $this->postJson("/api/v1/tender-management/participations/{$id}/submit")->assertUnprocessable()->assertJsonPath('code', 'deadline_passed');
});
