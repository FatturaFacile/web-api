<?php

declare(strict_types=1);

use Database\Seeders\Translations\IntercompanyFrontendTranslations;
use Modules\GeneralLedger\Enums\ConsolidationAdjustmentType;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\Intercompany\Enums\AnomalyType;
use Modules\Intercompany\Enums\DocumentKind;
use Modules\Intercompany\Enums\LinkStatus;
use Modules\Intercompany\Enums\MatchStatus;
use Modules\Intercompany\Enums\MirrorMode;
use Modules\Intercompany\Enums\NettingStatus;
use Modules\Intercompany\Enums\PartyRole;
use Modules\Intercompany\Enums\PaymentState;
use Modules\Intercompany\Enums\RateType;
use Modules\Intercompany\Enums\RunStatus;
use Modules\Intercompany\Enums\Standard;
use Modules\Intercompany\Enums\SyncEventType;

/**
 * The texts of the module live in the translation seeder only: every key has the four languages (Italian serves it-CH and
 * it-IT, German de-CH, French fr-CH, English en), the placeholders agree, every enum code and every error code the API can
 * return has a label, and no key is also defined by another frontend dictionary.
 */
test('every key has a text in all four languages with the same placeholders', function (): void {
    foreach (IntercompanyFrontendTranslations::definitions() as $key => $tuple) {
        expect($tuple)->toHaveCount(4);

        $placeholders = null;

        foreach ($tuple as $index => $text) {
            expect(trim($text))->not->toBe('', "{$key} is empty in language {$index}");

            preg_match_all('/\{[a-zA-Z]+\}/', $text, $found);
            sort($found[0]);
            $placeholders ??= $found[0];

            expect($found[0])->toBe($placeholders, "{$key} has different placeholders in language {$index}");
        }
    }
});

test('the four languages resolve the same keys', function (): void {
    $keys = array_keys(IntercompanyFrontendTranslations::all('it'));

    foreach (['en', 'de', 'fr'] as $locale) {
        expect(array_keys(IntercompanyFrontendTranslations::all($locale)))->toBe($keys);
    }

    expect(fn () => IntercompanyFrontendTranslations::all('es'))->toThrow(InvalidArgumentException::class);
});

test('the keys belong to the intercompany namespaces and no other dictionary defines them', function (): void {
    $keys = array_keys(IntercompanyFrontendTranslations::definitions());

    foreach ($keys as $key) {
        expect($key)->toMatch('/^(intercompany\.|NAV\.INTERCOMPANY|PAGE_TITLE\.INTERCOMPANY)/');
    }

    foreach (glob(base_path('database/seeders/Translations/*FrontendTranslations.php')) ?: [] as $file) {
        $class = 'Database\\Seeders\\Translations\\'.basename($file, '.php');

        if ($class === IntercompanyFrontendTranslations::class || ! method_exists($class, 'definitions')) {
            continue;
        }

        expect(array_intersect($keys, array_keys($class::definitions())))->toBe([], "{$class} redefines a key of the intercompany module");
    }
});

test('every enum code has a label', function (): void {
    $enums = [
        'mirrorMode' => MirrorMode::codes(),
        'linkStatus' => LinkStatus::codes(),
        'documentType' => DocumentKind::codes(),
        'paymentStatus' => PaymentState::codes(),
        'nettingStatus' => NettingStatus::codes(),
        'matchStatus' => MatchStatus::codes(),
        'anomalyType' => AnomalyType::codes(),
        'rateType' => RateType::codes(),
        'runStatus' => RunStatus::codes(),
        'standard' => Standard::codes(),
        'partyRole' => PartyRole::codes(),
        'syncEvent' => SyncEventType::codes(),
        'eliminationType' => [...array_map(static fn (ConsolidationAdjustmentType $case): string => $case->value, ConsolidationAdjustmentType::cases()), 'manual'],
        'consolidationMethod' => array_map(static fn (ConsolidationMethod $case): string => $case->value, ConsolidationMethod::cases()),
    ];

    $definitions = IntercompanyFrontendTranslations::definitions();

    foreach ($enums as $name => $codes) {
        foreach ($codes as $code) {
            expect($definitions)->toHaveKey("intercompany.enum.{$name}.{$code}");
        }
    }
});

test('every error code the API returns has a label', function (): void {
    $codes = [
        'invalid', 'conflict', 'entity_not_in_group', 'party_link_missing', 'party_link_exists', 'source_missing', 'mirror_failed', 'mirror_not_draft',
        'mirror_not_available', 'payment_variance', 'stale_version', 'netting_disabled', 'netting_not_draft', 'same_entity', 'rate_missing', 'rate_invalid',
        'rate_group_currency', 'counterpart_missing', 'counterpart_taken', 'item_closed', 'period_invalid', 'run_locked', 'run_not_locked', 'illegal_transition',
        'inputs_changed', 'adjustment_unbalanced', 'adjustment_line_invalid', 'adjustment_void',
    ];

    $definitions = IntercompanyFrontendTranslations::definitions();

    foreach ($codes as $code) {
        expect($definitions)->toHaveKey("intercompany.error.{$code}");
    }
});

test('the anomaly reasons, the standards and the statement sections have labels', function (): void {
    $definitions = IntercompanyFrontendTranslations::definitions();

    foreach (['in_transit', 'missing_in_counterparty', 'missing_in_issuer', 'currency_mismatch', 'rate_difference', 'tax_difference', 'amount_difference', 'mirror_draft', 'booking_date_gap', 'payment_lag', 'unmatched_by_user'] as $reason) {
        expect($definitions)->toHaveKey("intercompany.reconciliation.reason.{$reason}");
    }

    foreach (Standard::codes() as $standard) {
        expect($definitions)->toHaveKey("intercompany.standard.{$standard}")
            ->toHaveKey("intercompany.standard.{$standard}.description")
            ->toHaveKey("intercompany.statements.{$standard}.translation_reserve")
            ->toHaveKey("intercompany.statements.{$standard}.nci");
    }

    foreach (['assets', 'liabilities', 'equity', 'revenue', 'cogs', 'operating_expenses', 'value_of_production', 'cost_of_production'] as $section) {
        expect($definitions)->toHaveKey("intercompany.statements.section.{$section}");
    }

    foreach (['result_of_the_period', 'non_cash_expenses', 'change_receivables', 'change_inventories', 'change_other_current_assets', 'change_current_liabilities', 'change_unclassified', 'net_investment', 'equity_movements', 'non_current_liabilities', 'translation_effect'] as $line) {
        expect($definitions)->toHaveKey("intercompany.statements.cashflow.line.{$line}");
    }
});

test('the legal acronyms stay untranslated', function (): void {
    $definitions = IntercompanyFrontendTranslations::definitions();

    foreach (['intercompany.standard.fer30', 'intercompany.standard.ifrs10', 'intercompany.standard.oic17'] as $key) {
        expect(array_unique($definitions[$key]))->toHaveCount(1);
    }

    expect($definitions['intercompany.standard.fer30'][0])->toBe('Swiss GAAP FER 30')
        ->and($definitions['intercompany.standard.ifrs10'][0])->toBe('IFRS 10')
        ->and($definitions['intercompany.standard.oic17'][0])->toBe('OIC 17');
});

test('the validation attribute dictionaries of the four languages know the new request fields', function (): void {
    foreach (['it', 'en', 'de', 'fr'] as $locale) {
        $attributes = require base_path("lang/{$locale}/validation_attributes.php");

        foreach (['entity_tenant_id', 'mirror_mode', 'amount_tolerance', 'expected_version', 'as_of_date', 'counterpart_document_id', 'adjustment_amount', 'fiscal_period_id'] as $field) {
            expect($attributes)->toHaveKey($field);
        }
    }
});

test('every static key the Angular client asks for exists', function (): void {
    $keys = [
        'intercompany.common.actions',
        'intercompany.common.add',
        'intercompany.common.confirmDelete',
        'intercompany.common.deleted',
        'intercompany.common.next',
        'intercompany.common.noGroups',
        'intercompany.common.openLedgerConsolidation',
        'intercompany.common.saved',
        'intercompany.error.forbidden',
        'intercompany.error.generic',
        'intercompany.error.not_found',
        'intercompany.error.validation',
        'intercompany.package.download',
        'intercompany.package.generated',
        'intercompany.package.hint',
        'intercompany.reconciliation.amountA',
        'intercompany.reconciliation.amountB',
        'intercompany.reconciliation.asOf',
        'intercompany.reconciliation.batch',
        'intercompany.reconciliation.batchDone',
        'intercompany.reconciliation.clearCell',
        'intercompany.reconciliation.difference',
        'intercompany.reconciliation.empty',
        'intercompany.reconciliation.entityA',
        'intercompany.reconciliation.entityB',
        'intercompany.reconciliation.kpiItems',
        'intercompany.reconciliation.matched',
        'intercompany.reconciliation.matrixCaption',
        'intercompany.reconciliation.matrixHint',
        'intercompany.reconciliation.runMatch',
        'intercompany.reconciliation.runWithoutMatch',
        'intercompany.reconciliation.status',
        'intercompany.run.adjustments',
        'intercompany.run.advanceCalculating',
        'intercompany.run.advanced',
        'intercompany.run.advanceReview',
        'intercompany.run.amount',
        'intercompany.run.balanced',
        'intercompany.run.check',
        'intercompany.run.detail',
        'intercompany.run.effectiveOwnership',
        'intercompany.run.eliminationType',
        'intercompany.run.failed',
        'intercompany.run.lock',
        'intercompany.run.nciBreakdown',
        'intercompany.run.nciMovement',
        'intercompany.run.newRun',
        'intercompany.run.noEliminations',
        'intercompany.run.noHistory',
        'intercompany.run.noRun',
        'intercompany.run.passed',
        'intercompany.run.post',
        'intercompany.run.reopened',
        'intercompany.run.revalidate',
        'intercompany.run.scopeHint',
        'intercompany.run.scopeNotReady',
        'intercompany.run.scopeReady',
        'intercompany.run.share',
        'intercompany.run.start',
        'intercompany.run.started',
        'intercompany.run.step.close',
        'intercompany.run.step.eliminations',
        'intercompany.run.step.scope',
        'intercompany.run.step.translation',
        'intercompany.run.unbalanced',
        'intercompany.run.version',
        'intercompany.run.warnings',
        'intercompany.statements.account',
        'intercompany.statements.closingCash',
        'intercompany.statements.direct',
        'intercompany.statements.effective',
        'intercompany.statements.financing',
        'intercompany.statements.fxEffect',
        'intercompany.statements.investing',
        'intercompany.statements.item',
        'intercompany.statements.nciNetAssets',
        'intercompany.statements.nciOpening',
        'intercompany.statements.nciProfit',
        'intercompany.statements.nciSchedule',
        'intercompany.statements.netChange',
        'intercompany.statements.openingCash',
        'intercompany.statements.operating',
        'intercompany.statements.shareholdings',
        'intercompany.statements.standard',
        'intercompany.statements.translationReserve',
        'intercompany.statements.voting',
        'intercompany.structure.basis',
        'intercompany.structure.currency',
        'intercompany.structure.dateTolerance',
        'intercompany.structure.editInLedger',
        'intercompany.structure.entity',
        'intercompany.structure.holder',
        'intercompany.structure.ownership',
        'intercompany.structure.ownershipHint',
        'intercompany.structure.parent',
        'intercompany.structure.partyLinksHint',
        'intercompany.structure.periodRatesHint',
        'intercompany.transactions.asOf',
        'intercompany.transactions.confirmDone',
        'intercompany.transactions.confirmNetting',
        'intercompany.transactions.createMirror',
        'intercompany.transactions.detail',
        'intercompany.transactions.document',
        'intercompany.transactions.entityA',
        'intercompany.transactions.entityB',
        'intercompany.transactions.fxVariance',
        'intercompany.transactions.kpiTotal',
        'intercompany.transactions.mirrorDone',
        'intercompany.transactions.netAmount',
        'intercompany.transactions.netDirection',
        'intercompany.transactions.netting',
        'intercompany.transactions.nettingConfirmed',
        'intercompany.transactions.nettingHint',
        'intercompany.transactions.nettingProposed',
        'intercompany.transactions.paidVariance',
        'intercompany.transactions.pairId',
        'intercompany.transactions.payableTotal',
        'intercompany.transactions.proposeNetting',
        'intercompany.transactions.receivableTotal',
        'intercompany.transactions.reverseDone',
        'intercompany.transactions.scanDone',
        'intercompany.transactions.source',
        'intercompany.transactions.status',
        'intercompany.transactions.syncDone',
        'intercompany.transactions.syncLog',
    ];

    $definitions = IntercompanyFrontendTranslations::definitions();

    foreach ($keys as $key) {
        expect($definitions)->toHaveKey($key);
    }
});

test('every code of the dynamic prefixes of the client has a text', function (): void {
    $definitions = IntercompanyFrontendTranslations::definitions();

    $prefixes = [
        'intercompany.package.section' => ['balance_sheet', 'income_statement', 'cash_flow', 'shareholdings', 'nci_schedule', 'reciprocal_reconciliation', 'elimination_ledger', 'translation'],
        'intercompany.reconciliation.matchKind' => ['exact', 'tolerance', 'manual'],
        'intercompany.run.action' => ['advance', 'reopen', 'lock', 'post'],
        'intercompany.run.checks' => ['has_parent', 'has_members', 'ownership_valid', 'holder_in_group', 'currency_defined', 'rate_available', 'trial_balance_ready'],
        'intercompany.run.step' => ['scope', 'translation', 'eliminations', 'review', 'close'],
        'intercompany.transactions.direction' => ['a_pays_b', 'b_pays_a', 'balanced'],
        'intercompany.error' => ['generic', 'forbidden', 'not_found', 'conflict', 'validation'],
    ];

    foreach ($prefixes as $prefix => $codes) {
        foreach ($codes as $code) {
            expect($definitions)->toHaveKey("{$prefix}.{$code}");
        }
    }
});
