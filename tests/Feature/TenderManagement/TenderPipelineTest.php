<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\TenderManagement\Models\TenderNotice;

require_once __DIR__.'/TenderManagementSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    tndSetUp($this);
    Storage::fake('local');
});

it('creates a tender notice and rejects malformed CIG and CUP codes', function (): void {
    $this->postJson('/api/v1/tender-management/notices', tndNoticePayload($this))
        ->assertCreated()
        ->assertJsonPath('data.jurisdiction', 'IT_Dlgs36_2023_ANAC')
        ->assertJsonPath('data.base_auction_amount', '1000000.00')
        ->assertJsonPath('data.status', 'open');

    $this->postJson('/api/v1/tender-management/notices', tndNoticePayload($this, ['cig_code_it' => 'short', 'cup_code_it' => '123']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cig_code_it', 'cup_code_it']);
});

it('requires a scoring formula for the most economically advantageous tender', function (): void {
    $this->postJson('/api/v1/tender-management/notices', tndNoticePayload($this, ['formula_type' => null]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'formula_required');
});

it('lists, filters, updates and deletes notices of the company only', function (): void {
    $id = tndCreateNotice($this);
    tndCreateNotice($this, ['title' => 'Facility maintenance', 'jurisdiction' => 'CH_SIMAP_LAPub', 'currency' => 'CHF', 'award_criterion' => 'LowestPrice', 'formula_type' => null, 'simap_project_number' => '555111']);

    $this->getJson('/api/v1/tender-management/notices?jurisdiction=CH_SIMAP_LAPub')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.simap_project_number', '555111');
    $this->getJson('/api/v1/tender-management/notices?q=scolastico')->assertOk()->assertJsonCount(1, 'data');

    $this->putJson("/api/v1/tender-management/notices/{$id}", ['title' => 'Manutenzione straordinaria'])->assertOk()->assertJsonPath('data.title', 'Manutenzione straordinaria');
    $this->deleteJson("/api/v1/tender-management/notices/{$id}")->assertOk();
    $this->getJson("/api/v1/tender-management/notices/{$id}")->assertNotFound();
});

it('keeps the notices of another company out of reach', function (): void {
    $id = tndCreateNotice($this);
    $other = TenderNotice::query()->withoutGlobalScopes()->findOrFail($id);

    $stranger = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    treasuryActor($this, 'owner', $stranger);

    $this->getJson("/api/v1/tender-management/notices/{$other->id}")->assertNotFound();
    $this->getJson('/api/v1/tender-management/notices')->assertOk()->assertJsonCount(0, 'data');
});

it('opens a participation dossier with the default envelopes of the jurisdiction', function (): void {
    $id = tndCreateParticipation($this);

    $this->getJson("/api/v1/tender-management/participations/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'Scouting')
        ->assertJsonPath('data.notice.cig_code_it', 'ABC1234567')
        ->assertJsonPath('data.allowed_transitions', ['GoApproved', 'Lost'])
        ->assertJsonPath('data.administrative_envelope_docs.0.key', 'edgue')
        ->assertJsonPath('data.administrative_envelope_docs.0.signature_required', true)
        ->assertJsonPath('data.administrative_envelope_docs.0.status', 'missing')
        ->assertJsonPath('data.economic_envelope_docs.1.key', 'labor_safety_costs_declaration');
});

it('allows one dossier per tender and none for a closed tender', function (): void {
    $noticeId = tndCreateNotice($this);
    $this->postJson('/api/v1/tender-management/participations', ['tender_id' => $noticeId])->assertCreated();
    $this->postJson('/api/v1/tender-management/participations', ['tender_id' => $noticeId])->assertStatus(409)->assertJsonPath('code', 'participation_exists');

    $closed = tndCreateNotice($this, ['status' => 'closed']);
    $this->postJson('/api/v1/tender-management/participations', ['tender_id' => $closed])->assertUnprocessable()->assertJsonPath('code', 'notice_closed');
});

it('moves the dossier along the pipeline and refuses the other transitions', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/transition", ['status' => 'AwardedWon'])->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
    $this->postJson("/api/v1/tender-management/participations/{$id}/transition", ['status' => 'GoApproved'])->assertOk()->assertJsonPath('data.status', 'GoApproved');
    $this->postJson("/api/v1/tender-management/participations/{$id}/transition", ['status' => 'PreparingEnvelopes'])->assertOk()->assertJsonPath('data.status', 'PreparingEnvelopes');
    $this->postJson("/api/v1/tender-management/participations/{$id}/transition", ['status' => 'Lost'])->assertOk();
    $this->postJson("/api/v1/tender-management/participations/{$id}/transition", ['status' => 'LegalAppeal_Ricorso'])->assertOk()->assertJsonPath('data.status', 'LegalAppeal_Ricorso');
});

it('cannot submit a dossier that skipped the envelope preparation', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/submit")->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
});

it('serves the lookups the forms need', function (): void {
    $this->getJson('/api/v1/tender-management/lookups')
        ->assertOk()
        ->assertJsonPath('data.transitions.Submitted', ['AwardedWon', 'Lost'])
        ->assertJsonPath('data.bid_bond.allowed_base_percentages', ['1.00', '2.00'])
        ->assertJsonPath('data.envelope_templates.CH.administrative.1.key', 'betreibungsauszug')
        ->assertJsonFragment(['value' => 'CH_AttestatoSUVA', 'jurisdiction' => 'CH', 'bond_reduction_key' => null, 'max_age_months' => null]);
});

it('builds the dashboard of the pipeline', function (): void {
    tndCreateParticipation($this);

    $this->getJson('/api/v1/tender-management/dashboard')
        ->assertOk()
        ->assertJsonPath('data.pipeline_counts.Scouting', 1)
        ->assertJsonCount(2, 'data.upcoming_deadlines')
        ->assertJsonPath('data.upcoming_deadlines.0.kind', 'clarification');
});

it('imports a simap.ch publication and refreshes it instead of duplicating it', function (): void {
    $payload = [
        'projectNumber' => '412345',
        'title' => ['de' => 'Unterhalt Gebäudetechnik', 'it' => 'Manutenzione impianti tecnici'],
        'procOfficeName' => ['de' => 'Bundesamt für Bauten und Logistik'],
        'procedureType' => 'open',
        'cpvCodes' => [['code' => '50700000-2']],
        'questionDeadline' => '2027-04-01 12:00:00',
        'offerDeadline' => '2027-04-20 17:00:00',
        'estimatedValue' => 750000,
    ];

    $first = $this->postJson('/api/v1/tender-management/notices/ingest-simap', ['payload' => $payload])
        ->assertCreated()
        ->assertJsonPath('data.jurisdiction', 'CH_SIMAP_LAPub')
        ->assertJsonPath('data.currency', 'CHF')
        ->assertJsonPath('data.simap_project_number', '412345')
        ->assertJsonPath('data.cpv_category_codes', ['50700000'])
        ->assertJsonPath('data.base_auction_amount', '750000.00');

    $this->postJson('/api/v1/tender-management/notices/ingest-simap', ['payload' => array_merge($payload, ['estimatedValue' => 800000])])->assertCreated();

    expect(TenderNotice::query()->where('simap_project_number', '412345')->count())->toBe(1)
        ->and($first->json('data.title'))->not->toBeEmpty();

    $this->postJson('/api/v1/tender-management/notices/ingest-simap', ['payload' => ['description' => 'no id']])->assertUnprocessable()->assertJsonPath('code', 'simap_payload_invalid');
});

it('evaluates the Go/No-Go against the vault and stores the matrix on the dossier', function (): void {
    tndAddVaultDocument($this, 'IT_SOA_Attestation', ['soa_categories_classifications' => [['category' => 'OG1', 'classification' => 'IV']]])->assertCreated();
    tndAddVaultDocument($this, 'IT_DURC')->assertCreated();
    $noticeId = tndCreateNotice($this, ['required_soa_categories' => [['category' => 'OG1', 'classification' => 'III']], 'required_certifications' => ['IT_ISO9001']]);
    $id = tndCreateParticipation($this, $noticeId);

    $response = $this->postJson("/api/v1/tender-management/participations/{$id}/evaluate-eligibility")->assertOk();

    $response->assertJsonPath('data.strategy', 'IT')
        ->assertJsonPath('data.recommended_mode', 'Avvalimento')
        ->assertJsonPath('data.gaps.missing_certifications', ['IT_ISO9001'])
        ->assertJsonPath('data.gaps.soa.0.met', true);

    $this->getJson("/api/v1/tender-management/participations/{$id}")->assertOk()->assertJsonPath('data.go_no_go_score.decision', $response->json('data.decision'));
});

it('reserves the tender module to the finance roles', function (): void {
    treasuryActor($this, 'member');

    $this->getJson('/api/v1/tender-management/notices')->assertForbidden();
    $this->postJson('/api/v1/tender-management/notices', tndNoticePayload($this))->assertForbidden();
});
