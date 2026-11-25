<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/TenderManagementSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    tndSetUp($this);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tndOfferPayload(array $overrides = []): array
{
    return array_merge([
        'discount_percentage' => 10,
        'estimated_total_cost' => '700000',
        'company_safety_costs_declared' => '15000',
        'company_labor_costs_declared' => '320000',
        'bid_base_percentage' => '2.00',
        'technical_score_estimate' => 60,
        'best_competitor_discount' => 20,
    ], $overrides);
}

it('simulates the offer, the score and the bid bond without storing anything', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/economic-offer", tndOfferPayload())
        ->assertOk()
        ->assertJsonPath('data.offered_amount', '902000.00')
        ->assertJsonPath('data.net_margin_amount', '202000.00')
        ->assertJsonPath('data.scoring.economic_score', '15.00')
        ->assertJsonPath('data.scoring.total_score', '75.00')
        ->assertJsonPath('data.bid_bond.final_amount', '20000.00')
        ->assertJsonPath('data.anac_contribution_amount', '200.00')
        ->assertJsonPath('data.submission_blocked', false);

    $this->getJson("/api/v1/tender-management/participations/{$id}")->assertOk()->assertJsonPath('data.offered_amount', null);
});

it('stores the offer and the reduced bid bond on the dossier when asked to', function (): void {
    tndAddVaultDocument($this, 'IT_ISO9001')->assertCreated();
    tndAddVaultDocument($this, 'IT_PMI_Status')->assertCreated();
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/economic-offer", tndOfferPayload(['persist' => true]))
        ->assertOk()
        ->assertJsonPath('data.bid_bond.final_amount', '7000.00');

    $this->getJson("/api/v1/tender-management/participations/{$id}")
        ->assertOk()
        ->assertJsonPath('data.economic_discount_percentage_offered', '10.0000')
        ->assertJsonPath('data.offered_amount', '902000.00')
        ->assertJsonPath('data.bid_bond_base_percentage', '2.00')
        ->assertJsonPath('data.bid_bond_final_amount', '7000.00')
        ->assertJsonPath('data.bid_bond_applied_reductions.0.key', 'iso9001')
        ->assertJsonPath('data.company_labor_costs_declared', '320000.00')
        ->assertJsonPath('data.anac_contribution_amount', '200.00');
});

it('blocks the submission of an Italian offer without the Art. 108 co. 9 cost split', function (): void {
    $id = tndCreateParticipation($this);

    $response = $this->postJson("/api/v1/tender-management/participations/{$id}/economic-offer", tndOfferPayload(['company_safety_costs_declared' => 0, 'company_labor_costs_declared' => null]))->assertOk();

    expect($response->json('data.submission_blocked'))->toBeTrue()
        ->and(array_column($response->json('data.issues'), 'code'))->toContain('art108_safety_costs_missing', 'art108_labor_costs_missing');
});

it('refuses a bid bond percentage other than one or two percent', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/economic-offer", tndOfferPayload(['bid_base_percentage' => '3.00']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'bid_bond_percentage_invalid');
});

it('validates the discount range', function (): void {
    $id = tndCreateParticipation($this);

    $this->postJson("/api/v1/tender-management/participations/{$id}/economic-offer", tndOfferPayload(['discount_percentage' => 120]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['discount_percentage']);
});

it('simulates a Swiss tender without the Italian cost split or the ANAC contribution', function (): void {
    $noticeId = tndCreateNotice($this, ['jurisdiction' => 'CH_SIMAP_LAPub', 'currency' => 'CHF', 'award_criterion' => 'LowestPrice', 'formula_type' => null, 'simap_project_number' => '99001']);
    $id = tndCreateParticipation($this, $noticeId);

    $this->postJson("/api/v1/tender-management/participations/{$id}/economic-offer", tndOfferPayload(['company_safety_costs_declared' => null, 'company_labor_costs_declared' => null, 'estimated_total_cost' => '600000', 'competitor_discounts' => [5, 10, 15]]))
        ->assertOk()
        ->assertJsonPath('data.submission_blocked', false)
        ->assertJsonPath('data.anac_contribution_amount', null)
        ->assertJsonPath('data.scoring.anomaly.threshold_type', 'average_discount')
        ->assertJsonPath('data.scoring.anomaly.is_anomalous', false);
});
