<?php

declare(strict_types=1);

use Database\Seeders\Translations\ExpenseManagementFrontendTranslations;

it('has the four languages for every expense management key and no empty text', function (): void {
    $definitions = ExpenseManagementFrontendTranslations::definitions();
    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $tuple) {
        expect($tuple)->toHaveCount(4);

        foreach ($tuple as $text) {
            expect(trim($text))->not->toBe('');
        }
    }

    $keys = array_keys($definitions);

    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        expect(array_keys(ExpenseManagementFrontendTranslations::all($locale)))->toBe($keys);
    }
});

it('has a label for every value the API reports', function (): void {
    $keys = array_keys(ExpenseManagementFrontendTranslations::definitions());

    $groups = [
        'status' => ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'posted', 'reimbursed', 'cancelled'],
        'paymentMethod' => ['employee_out_of_pocket', 'corporate_card'],
        'calculationSource' => ['CH_TCS', 'CH_SPESENREGLEMENT', 'IT_ACI_TABLE'],
        'destinationType' => ['municipality', 'domestic', 'international'],
        'allowanceMethod' => ['flat_rate', 'mixed', 'analytic'],
        'reconciliationStatus' => ['unmatched', 'matched', 'ignored', 'disputed'],
        'severity' => ['warning', 'blocking'],
        'channel' => ['pain001', 'payroll'],
        'category' => ['meals', 'lodging', 'local_transport', 'fuel', 'mileage', 'tolls_parking', 'supplies', 'representation', 'other'],
        'violation' => ['duplicate_receipt_hash', 'duplicate_merchant_date_amount', 'exceeds_item_cap', 'exceeds_daily_cap', 'receipt_required', 'non_traceable_payment', 'taxable_per_diem_excess', 'missing_merchant_tax_id', 'outside_report_period', 'future_date', 'expense_too_old', 'low_ocr_confidence', 'vat_not_recoverable'],
        'fiscal' => ['it_art51_tuir', 'it_traceable_payment', 'ch_spesenreglement', 'ch_tcs_mileage', 'it_aci_tables', 'it_per_diem_limits'],
        'error' => ['report_not_editable', 'report_empty', 'blocking_violations', 'missing_receipt', 'invalid_transition', 'self_approval_forbidden', 'rejection_reason_required', 'no_reimbursable_amount', 'employee_iban_missing', 'card_transaction_mismatch', 'card_transaction_already_matched', 'vehicle_rate_not_found', 'currency_rate_missing', 'receipt_not_found', 'period_invalid', 'category_not_found', 'amount_exceeds_transaction', 'debtor_account_missing', 'approved_amount_exceeds', 'generic'],
        'audit' => ['created', 'updated', 'line_added', 'line_updated', 'line_removed', 'mileage_added', 'per_diem_added', 'per_diem_removed', 'review_started', 'card_matched', 'card_unmatched', 'submitted', 'approved', 'rejected', 'payout_exported', 'reimbursed', 'cancelled'],
    ];

    foreach ($groups as $group => $values) {
        foreach ($values as $value) {
            expect($keys)->toContain("expenseManagement.{$group}.{$value}");
        }
    }
});

it('keeps the Art. 51 TUIR thresholds in the Italian notice', function (): void {
    $notice = ExpenseManagementFrontendTranslations::all('it')['expenseManagement.fiscal.it_per_diem_limits'];

    expect($notice)->toContain('46,48')->toContain('77,47')->toContain('15,49')->toContain('25,82');
});
