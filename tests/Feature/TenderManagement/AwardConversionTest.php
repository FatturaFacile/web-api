<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Projects\Models\Project;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\TenderManagement\Models\TenderParticipation;

require_once __DIR__.'/TenderManagementSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    tndSetUp($this);
});

it('converts an awarded Italian tender into a project and a contract that inherit CIG, CUP, RUP and the dedicated account', function (): void {
    $id = tndCreateParticipation($this);
    $this->postJson("/api/v1/tender-management/participations/{$id}/economic-offer", tndOfferPayloadForConversion())->assertOk();
    tndForceStatus($id, 'AwardedWon');

    $response = $this->postJson("/api/v1/tender-management/participations/{$id}/convert", ['planned_start_date' => '2027-03-01', 'planned_end_date' => '2028-02-29'])
        ->assertCreated()
        ->assertJsonPath('data.project.cig_code', 'ABC1234567')
        ->assertJsonPath('data.project.cup_code', 'ABCDEFGHIJ12345')
        ->assertJsonPath('data.references.rup', 'Mario Rossi')
        ->assertJsonPath('data.references.dedicated_bank_account', 'IT60X0542811101000000123456')
        ->assertJsonPath('data.references.sdi_reference_block', 'contract');

    $project = Project::query()->findOrFail($response->json('data.project.id'));
    $contract = ServiceContract::query()->findOrFail($response->json('data.contract.id'));

    expect($project->contact_id)->toBe($this->contact->id)
        ->and($project->project_manager_user_id)->toBe($this->owner->id)
        ->and($project->sdi_reference_block)->toBe('contract')
        ->and((float) $project->contract_value)->toBe(902000.0)
        ->and($contract->customer_id)->toBe($this->contact->id)
        ->and($contract->metadata['references']['cig'])->toBe('ABC1234567')
        ->and($contract->metadata['project_id'])->toBe($project->id);

    $dossier = TenderParticipation::query()->findOrFail($id);
    expect($dossier->converted_contract_id)->toBe($contract->id)->and($dossier->converted_project_job_id)->toBe($project->id);
});

it('converts atomically and only once', function (): void {
    $id = tndCreateParticipation($this);
    tndForceStatus($id, 'AwardedWon');

    $this->postJson("/api/v1/tender-management/participations/{$id}/convert")->assertCreated();
    $this->postJson("/api/v1/tender-management/participations/{$id}/convert")->assertStatus(409)->assertJsonPath('code', 'already_converted');

    expect(Project::query()->count())->toBe(1)->and(ServiceContract::query()->count())->toBe(1);
});

it('converts only a tender that was awarded', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/convert")->assertUnprocessable()->assertJsonPath('code', 'not_awarded');
    expect(Project::query()->count())->toBe(0);
});

it('asks for the contact to invoice when the notice has none', function (): void {
    $noticeId = tndCreateNotice($this, ['contracting_authority_contact_id' => null]);
    $id = tndCreateParticipation($this, $noticeId);
    tndForceStatus($id, 'AwardedWon');

    $this->postJson("/api/v1/tender-management/participations/{$id}/convert")->assertUnprocessable()->assertJsonPath('code', 'contracting_authority_contact_required');
    $this->postJson("/api/v1/tender-management/participations/{$id}/convert", ['contact_id' => $this->contact->id])->assertCreated();
});

it('carries the simap.ch project number instead of the Italian codes on a Swiss award', function (): void {
    $noticeId = tndCreateNotice($this, ['jurisdiction' => 'CH_SIMAP_LAPub', 'currency' => 'CHF', 'award_criterion' => 'LowestPrice', 'formula_type' => null, 'simap_project_number' => '412345', 'cig_code_it' => null, 'cup_code_it' => null]);
    $id = tndCreateParticipation($this, $noticeId);
    tndForceStatus($id, 'AwardedWon');

    $response = $this->postJson("/api/v1/tender-management/participations/{$id}/convert")
        ->assertCreated()
        ->assertJsonPath('data.references.simap_project_number', '412345')
        ->assertJsonPath('data.references.cig', null);

    $project = Project::query()->findOrFail($response->json('data.project.id'));
    expect($project->cig_code)->toBeNull()->and($project->currency)->toBe('CHF');
});

it('keeps the conversion to the finance roles', function (): void {
    $id = tndCreateParticipation($this);
    tndForceStatus($id, 'AwardedWon');
    treasuryActor($this, 'accountant_readonly');

    $this->postJson("/api/v1/tender-management/participations/{$id}/convert")->assertForbidden();
});

/**
 * @return array<string, mixed>
 */
function tndOfferPayloadForConversion(): array
{
    return [
        'discount_percentage' => 10, 'estimated_total_cost' => '700000', 'company_safety_costs_declared' => '15000',
        'company_labor_costs_declared' => '320000', 'bid_base_percentage' => '2.00', 'technical_score_estimate' => 60, 'persist' => true,
    ];
}
