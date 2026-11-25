<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Models\TenderParticipation;

/**
 * Builders of the pure (unsaved) tender models used by the unit tests of the tender management module (Mod 169).
 */
function tndToday(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-15 10:00:00');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tndNotice(array $overrides = []): TenderNotice
{
    return new TenderNotice(array_merge([
        'title' => 'Manutenzione scuola',
        'jurisdiction' => 'IT_Dlgs36_2023_ANAC',
        'procedure_type' => 'open',
        'contracting_authority_name' => 'Comune di Esempio',
        'cig_code_it' => 'ABC1234567',
        'cup_code_it' => 'ABCDEFGHIJ12345',
        'rup_name' => 'Mario Rossi',
        'dedicated_bank_account_it' => 'IT60X0542811101000000123456',
        'award_criterion' => 'OEPV_MostEconomicallyAdvantageous',
        'formula_type' => 'Linear',
        'currency' => 'EUR',
        'base_auction_amount' => '1000000.00',
        'non_subject_safety_costs_amount' => '20000.00',
        'estimated_labor_cost_amount' => '300000.00',
        'technical_max_score' => '70',
        'economic_max_score' => '30',
        'turnover_reference_years' => 3,
        'required_similar_projects_count' => 0,
        'status' => 'open',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tndDoc(string $type, array $overrides = []): QualificationVaultDocument
{
    return new QualificationVaultDocument(array_merge([
        'doc_type' => $type,
        'document_number' => $type.'-1',
        'issue_date' => tndToday()->subMonths(1)->toDateString(),
        'expiry_date' => tndToday()->addYears(2)->toDateString(),
        'verification_status' => 'Valid',
    ], $overrides));
}

/**
 * @param  list<QualificationVaultDocument>  $documents
 * @return Collection<int, QualificationVaultDocument>
 */
function tndVault(array $documents): Collection
{
    return new Collection($documents);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tndParticipation(array $overrides = []): TenderParticipation
{
    return new TenderParticipation(array_merge(['participation_mode' => 'SingleCompany', 'status' => 'PreparingEnvelopes'], $overrides));
}
