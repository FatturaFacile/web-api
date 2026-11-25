<?php

declare(strict_types=1);

use Database\Seeders\Translations\TenderManagementFrontendTranslations;
use Database\Seeders\TranslationSeeder;

it('has the four languages for every tender management key and no empty text', function (): void {
    $definitions = TenderManagementFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('');
        }
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(TenderManagementFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('prefixes every key with tenderManagement except the navigation entries', function (): void {
    foreach (array_keys(TenderManagementFrontendTranslations::definitions()) as $key) {
        expect(str_starts_with($key, 'tenderManagement.') || str_starts_with($key, 'NAV.TENDER_MANAGEMENT') || str_starts_with($key, 'PAGE_TITLE.TENDER_MANAGEMENT'))
            ->toBeTrue("Unexpected key {$key}");
    }
});

it('does not overlap with the base translations', function (): void {
    foreach (['it', 'en'] as $locale) {
        $base = array_keys((new TranslationSeeder)->dictionary($locale));
        $overlap = array_intersect(array_keys(TenderManagementFrontendTranslations::definitions()), $base);

        expect($overlap)->toBe([]);
    }
});

it('has a label for every value the API reports', function (): void {
    $keys = array_keys(TenderManagementFrontendTranslations::definitions());

    $groups = [
        'enum.jurisdiction' => ['CH_SIMAP_LAPub', 'IT_Dlgs36_2023_ANAC', 'EU_TED', 'PrivateRfp'],
        'enum.procedureType' => ['open', 'selective', 'invitation', 'direct_award'],
        'enum.awardCriterion' => ['OEPV_MostEconomicallyAdvantageous', 'LowestPrice'],
        'enum.formulaType' => ['Linear', 'Bilinear', 'Interpolation'],
        'enum.noticeStatus' => ['open', 'closed', 'awarded', 'cancelled'],
        'enum.participationMode' => ['SingleCompany', 'RTI_Consorzio_Mandataria_Mandante', 'Avvalimento'],
        'enum.participationStatus' => ['Scouting', 'GoApproved', 'PreparingEnvelopes', 'Submitted', 'AwardedWon', 'Lost', 'LegalAppeal_Ricorso'],
        'enum.verificationStatus' => ['Valid', 'ExpiringSoon', 'Expired', 'PendingRenewal'],
        'enum.vaultDocType' => ['IT_SOA_Attestation', 'IT_DURC', 'IT_VisuraCamerale', 'IT_CasellarioGiudiziale_CarichiPendenti', 'IT_ISO9001', 'IT_ISO14001', 'IT_ISO45001', 'IT_UNI_PdR_125_ParitaGenere', 'IT_EMAS', 'IT_PMI_Status', 'CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug', 'CH_AttestatoAVS_AI_IPG', 'CH_AttestatoSUVA', 'CH_AttestatoLPP', 'CH_AttestatoAFC_IVA', 'CH_RispettoCCL', 'CH_ParitaSalarialeLogib'],
        'enum.envelope' => ['administrative', 'technical', 'economic'],
        'enum.alertSeverity' => ['info', 'warning', 'critical'],
        'enum.alertType' => ['submission_deadline', 'clarification_deadline', 'vault_expiry', 'soa_milestone', 'betreibungsauszug_age'],
        'enum.decision' => ['go', 'conditional', 'no_go'],
        'enum.signatureStatus' => ['valid', 'invalid', 'unsigned', 'not_required'],
        'enum.signatureFormat' => ['p7m', 'pades', 'none', 'unknown'],
        'enum.itemStatus' => ['missing', 'uploaded', 'signature_invalid', 'ok'],
        'error' => ['generic', 'invalid_transition', 'participation_exists', 'notice_closed', 'deadline_passed', 'envelopes_incomplete', 'readiness_blocked', 'safety_costs_required', 'labor_costs_required', 'bid_bond_percentage_invalid', 'discount_out_of_range', 'formula_required', 'not_awarded', 'already_converted', 'contracting_authority_contact_required', 'project_manager_required', 'invalid_signature_format', 'signature_invalid', 'vault_file_required', 'document_type_jurisdiction_mismatch', 'unknown_envelope', 'unknown_envelope_document', 'edgue_unsupported_jurisdiction', 'simap_payload_invalid', 'file_too_large'],
        'compliance' => ['art108_safety_costs_missing', 'art108_labor_costs_missing', 'art108_labor_costs_below_estimate', 'cig_missing', 'cup_missing', 'rup_missing', 'dedicated_account_missing', 'soa_missing', 'soa_expired', 'soa_category_missing', 'soa_classification_insufficient', 'soa_intermediate_check_due', 'fvoe_not_synced', 'durc_missing', 'durc_expired', 'edgue_missing', 'bid_bond_missing', 'bid_bond_percentage_invalid', 'anac_contribution_receipt_missing', 'document_missing', 'document_expired', 'document_expiring', 'betreibungsauszug_missing', 'betreibungsauszug_too_old', 'logib_missing', 'logib_deviation_exceeded', 'simap_number_missing', 'clarification_deadline_passed', 'submission_deadline_passed', 'turnover_gap', 'similar_projects_gap', 'anomaly_threshold_exceeded', 'offer_below_cost', 'margin_negative', 'rti_recommended', 'avvalimento_recommended', 'solo_eligible', 'signature_invalid'],
        'alert' => ['submission_deadline', 'clarification_deadline', 'vault_expiry', 'soa_milestone', 'betreibungsauszug_age'],
    ];

    foreach ($groups as $group => $values) {
        foreach ($values as $value) {
            expect($keys)->toContain("tenderManagement.{$group}.{$value}");
        }
    }
});

it('keeps the placeholders of the parametrised texts in every language', function (): void {
    $parametrised = [
        'tenderManagement.compliance.soa_classification_insufficient' => ['{category}', '{required}', '{available}'],
        'tenderManagement.compliance.logib_deviation_exceeded' => ['{value}', '{limit}'],
        'tenderManagement.compliance.document_expiring' => ['{type}', '{days}'],
        'tenderManagement.compliance.similar_projects_gap' => ['{required}', '{available}'],
        'tenderManagement.compliance.rti_recommended' => ['{quota}'],
        'tenderManagement.compliance.turnover_gap' => ['{gap}'],
        'tenderManagement.compliance.betreibungsauszug_too_old' => ['{months}'],
    ];

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $texts = TenderManagementFrontendTranslations::all($locale);

        foreach ($parametrised as $key => $placeholders) {
            foreach ($placeholders as $placeholder) {
                expect($texts[$key])->toContain($placeholder);
            }
        }
    }
});
