<?php

declare(strict_types=1);

use Modules\TenderManagement\Domain\Enums\Jurisdiction;
use Modules\TenderManagement\Domain\Enums\VerificationStatus;
use Modules\TenderManagement\Domain\Support\SoaClassification;
use Modules\TenderManagement\Domain\ValueObjects\ComplianceIssue;
use Modules\TenderManagement\Services\VaultStatusResolver;
use Modules\TenderManagement\Strategies\ChSimapLapubCiapStrategy;
use Modules\TenderManagement\Strategies\GenericTenderStrategy;
use Modules\TenderManagement\Strategies\ItDlgs36CodiceAppaltiAnacMepaStrategy;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;

require_once __DIR__.'/TenderUnitSupport.php';

/**
 * @param  list<ComplianceIssue>  $issues
 * @return list<string>
 */
function tndCodes(array $issues): array
{
    return array_map(static fn ($issue): string => $issue->code, $issues);
}

it('resolves the strategy of every jurisdiction', function (): void {
    $registry = app(TenderStrategyRegistry::class);

    expect($registry->for(Jurisdiction::SwissSimap))->toBeInstanceOf(ChSimapLapubCiapStrategy::class)
        ->and($registry->for(Jurisdiction::ItalianAnac))->toBeInstanceOf(ItDlgs36CodiceAppaltiAnacMepaStrategy::class)
        ->and($registry->for(Jurisdiction::EuTed))->toBeInstanceOf(GenericTenderStrategy::class)
        ->and($registry->for(Jurisdiction::PrivateRfp))->toBeInstanceOf(GenericTenderStrategy::class);
});

it('computes the ANAC tender contribution by value bracket', function (): void {
    $strategy = app(ItDlgs36CodiceAppaltiAnacMepaStrategy::class);

    expect($strategy->anacContribution(tndNotice(['base_auction_amount' => '100000.00'])))->toBe('0.00')
        ->and($strategy->anacContribution(tndNotice(['base_auction_amount' => '150000.00'])))->toBe('35.00')
        ->and($strategy->anacContribution(tndNotice(['base_auction_amount' => '1200000.00'])))->toBe('200.00')
        ->and($strategy->anacContribution(tndNotice(['base_auction_amount' => '30000000.00'])))->toBe('800.00')
        ->and(app(ChSimapLapubCiapStrategy::class)->anacContribution(tndNotice(['jurisdiction' => 'CH_SIMAP_LAPub'])))->toBeNull();
});

it('asks for the financial traceability codes of an Italian tender and carries them to the e-invoice', function (): void {
    $strategy = app(ItDlgs36CodiceAppaltiAnacMepaStrategy::class);
    $incomplete = tndNotice(['cig_code_it' => null, 'cup_code_it' => null, 'rup_name' => null, 'dedicated_bank_account_it' => null]);

    expect(tndCodes($strategy->validateNotice($incomplete)))->toBe(['cig_missing', 'cup_missing', 'rup_missing', 'dedicated_account_missing'])
        ->and($strategy->validateNotice(tndNotice()))->toBe([])
        ->and($strategy->billingReferences(tndNotice(), tndParticipation()))->toBe([
            'cig' => 'ABC1234567', 'cup' => 'ABCDEFGHIJ12345', 'rup' => 'Mario Rossi', 'dedicated_bank_account' => 'IT60X0542811101000000123456',
            'simap_project_number' => null, 'sdi_reference_block' => 'contract',
        ]);
});

it('keeps the simap.ch project number on a Swiss award instead of the Italian codes', function (): void {
    $notice = tndNotice(['jurisdiction' => 'CH_SIMAP_LAPub', 'simap_project_number' => '123456']);
    $references = app(ChSimapLapubCiapStrategy::class)->billingReferences($notice, tndParticipation());

    expect($references['simap_project_number'])->toBe('123456')->and($references['cig'])->toBeNull();
});

it('blocks the Italian submission on SOA gaps, a missing DURC, the eDGUE, the bid bond and the ANAC receipt', function (): void {
    $notice = tndNotice(['required_soa_categories' => [['category' => 'OG1', 'classification' => 'IV'], ['category' => 'OS28', 'classification' => 'II']]]);
    $vault = tndVault([tndDoc('IT_SOA_Attestation', ['soa_categories_classifications' => [['category' => 'OG1', 'classification' => 'III']]])]);

    $codes = tndCodes(app(ItDlgs36CodiceAppaltiAnacMepaStrategy::class)->validateSubmission($notice, tndParticipation(), $vault, tndToday()));

    expect($codes)->toContain('soa_classification_insufficient')
        ->and($codes)->toContain('soa_category_missing')
        ->and($codes)->toContain('durc_missing')
        ->and($codes)->toContain('edgue_missing')
        ->and($codes)->toContain('bid_bond_missing')
        ->and($codes)->toContain('anac_contribution_receipt_missing')
        ->and($codes)->toContain('art108_safety_costs_missing')
        ->and($codes)->toContain('fvoe_not_synced');
});

it('lets a complete Italian dossier through the submission gate', function (): void {
    $notice = tndNotice(['required_soa_categories' => [['category' => 'OG1', 'classification' => 'III']]]);
    $vault = tndVault([
        tndDoc('IT_SOA_Attestation', ['soa_categories_classifications' => [['category' => 'OG1', 'classification' => 'IV']], 'fvoe20_synced' => true, 'intermediate_triennial_check_date' => '2028-01-01']),
        tndDoc('IT_DURC', ['fvoe20_synced' => true]),
    ]);
    $participation = tndParticipation([
        'edgue_data' => ['version' => '3.3.0'], 'provisional_bid_bond_id' => '0196a1d0-0000-7000-8000-000000000001', 'anac_contribution_receipt_id' => 'RCP-1',
        'company_safety_costs_declared' => '15000.00', 'company_labor_costs_declared' => '320000.00',
    ]);

    $blocking = array_filter(app(ItDlgs36CodiceAppaltiAnacMepaStrategy::class)->validateSubmission($notice, $participation, $vault, tndToday()), static fn ($issue): bool => $issue->isBlocking());

    expect($blocking)->toBe([]);
});

it('refuses a submission after the peremptory deadline', function (): void {
    $notice = tndNotice(['submission_peremptory_deadline_at' => tndToday()->subHour()->toDateTimeString()]);

    expect(tndCodes(app(GenericTenderStrategy::class)->validateSubmission($notice, tndParticipation(), tndVault([]), tndToday())))->toBe(['submission_deadline_passed']);
});

it('rejects a Swiss debt-enforcement extract older than three months', function (): void {
    $strategy = app(ChSimapLapubCiapStrategy::class);
    $fresh = tndDoc('CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug', ['issue_date' => tndToday()->subMonths(2)->toDateString(), 'expiry_date' => null]);
    $old = tndDoc('CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug', ['issue_date' => tndToday()->subMonths(4)->toDateString(), 'expiry_date' => null]);

    expect(tndCodes($strategy->validateVaultDocument($fresh, tndToday())))->not->toContain('betreibungsauszug_too_old')
        ->and(tndCodes($strategy->validateVaultDocument($old, tndToday())))->toContain('betreibungsauszug_too_old')
        ->and(app(VaultStatusResolver::class)->statusAt($old, tndToday()))->toBe(VerificationStatus::Expired)
        ->and(app(VaultStatusResolver::class)->statusAt($fresh, tndToday()))->toBe(VerificationStatus::ExpiringSoon);
});

it('requires the Swiss social-security, tax and debt proofs and the Logib limit for the federal tenders', function (): void {
    $strategy = app(ChSimapLapubCiapStrategy::class);
    $notice = tndNotice(['jurisdiction' => 'CH_SIMAP_LAPub', 'simap_project_number' => '99', 'required_certifications' => ['CH_ParitaSalarialeLogib']]);

    $missing = tndCodes($strategy->validateSubmission($notice, tndParticipation(), tndVault([]), tndToday()));
    expect($missing)->toContain('betreibungsauszug_missing')->and($missing)->toContain('document_missing');

    $vault = tndVault([
        tndDoc('CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug', ['issue_date' => tndToday()->subDays(10)->toDateString(), 'expiry_date' => null]),
        tndDoc('CH_AttestatoAVS_AI_IPG'), tndDoc('CH_AttestatoSUVA'), tndDoc('CH_AttestatoLPP'), tndDoc('CH_AttestatoAFC_IVA'),
        tndDoc('CH_ParitaSalarialeLogib', ['numeric_value' => '7.50']),
    ]);
    $codes = tndCodes($strategy->validateSubmission($notice, tndParticipation(), $vault, tndToday()));

    expect($codes)->toBe(['logib_deviation_exceeded']);
});

it('orders the SOA classifications and covers a lower requirement with a higher class', function (): void {
    expect(SoaClassification::covers('IV', 'III'))->toBeTrue()
        ->and(SoaClassification::covers('III', 'III-bis'))->toBeFalse()
        ->and(SoaClassification::covers('iii-bis', 'III'))->toBeTrue()
        ->and(SoaClassification::covers('X', 'I'))->toBeFalse()
        ->and(SoaClassification::categories())->toContain('OG1', 'OG13', 'OS35');
});

it('tracks the five year SOA term and the triennial verification', function (): void {
    $resolver = app(VaultStatusResolver::class);
    $soa = tndDoc('IT_SOA_Attestation', ['issue_date' => '2022-01-10', 'expiry_date' => null, 'intermediate_triennial_check_date' => '2025-01-10']);

    expect($resolver->effectiveExpiry($soa)?->toDateString())->toBe('2027-01-10')
        ->and(tndCodes(app(ItDlgs36CodiceAppaltiAnacMepaStrategy::class)->validateVaultDocument($soa, tndToday())))->toContain('soa_intermediate_check_due');
});
