<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\StatutoryFinancials\Domain\Statement\LineCatalog;
use Modules\StatutoryFinancials\Domain\Statement\StatementBundle;
use Modules\StatutoryFinancials\Domain\Strategy\XbrlProfile;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Local validation of the XBRL instance and the cross-statement reconciliation, modelled on the checks of the Registro Imprese
 * (TEBENI) deposit tool. It checks the structure of the instance (well-formed XML, taxonomy reference, contexts, units, precision,
 * duplicate facts), the taxonomy tagging of the statement lines and the mathematics between the statements and the notes: the balance
 * sheet equation, the net result of the income statement against the balance sheet, the closing cash of the cash flow statement and the
 * quantitative tables of the notes. It does NOT replace the official taxonomy (XSD and formula linkbase) validation of the deposit
 * tool, which needs the taxonomy package of the release in force.
 */
final class XbrlValidationService
{
    public function __construct(private readonly TextTemplateService $templates) {}

    /**
     * @param  array<string, mixed>|null  $cashFlow
     * @param  array<string, mixed>|null  $notes
     * @return array<string, mixed>
     */
    public function validate(string $xml, XbrlProfile $profile, StatementBundle $bundle, ?array $cashFlow, ?array $notes, bool $notesRequired, string $locale): array
    {
        $issues = [];
        $add = function (string $severity, string $category, string $code, array $params = []) use (&$issues, $locale): void {
            $issues[] = ['severity' => $severity, 'category' => $category, 'code' => $code, 'params' => $params, 'message' => $this->templates->render($code, $locale, $params)['body']];
        };

        $factCount = $this->structure($xml, $profile, $bundle, $add);
        $this->statements($bundle, $cashFlow, $add);
        $this->notes($bundle, $notes, $notesRequired, $add);

        foreach ($bundle->warnings as $warning) {
            $add($warning['severity'] === 'info' ? 'info' : 'warning', 'reclassification', $warning['code'], $warning['params']);
        }

        $errors = array_values(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'error'));
        $warnings = array_values(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'warning'));

        return [
            'status' => $errors !== [] ? 'invalid' : ($warnings !== [] ? 'warnings' : 'valid'),
            'taxonomy_version' => $profile->taxonomyVersion,
            'entry_point' => $profile->entryPoint,
            'facts' => $factCount,
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => array_values(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'info')),
            'scope' => 'local_structure_and_reconciliation',
            'validated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  callable(string, string, string, array<string, mixed>=): void  $add
     */
    private function structure(string $xml, XbrlProfile $profile, StatementBundle $bundle, callable $add): int
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($xml);
        $problem = libxml_get_errors()[0]->message ?? 'invalid document';
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $add('error', 'schema', 'validation.xbrl_not_well_formed', ['error' => trim($problem)]);

            return 0;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xbrli', XbrlInstanceBuilder::XbrliNs);
        $xpath->registerNamespace('link', XbrlInstanceBuilder::LinkNs);
        $xpath->registerNamespace('t', $profile->namespace);

        if ($xpath->query('/xbrli:xbrl/link:schemaRef')->length === 0) {
            $add('error', 'schema', 'validation.xbrl_missing_schema_ref');
        }

        $contexts = [];

        foreach ($xpath->query('//xbrli:context') ?: [] as $context) {
            if ($context instanceof DOMElement) {
                $contexts[$context->getAttribute('id')] = true;
            }
        }

        $units = [];

        foreach ($xpath->query('//xbrli:unit') ?: [] as $unit) {
            if ($unit instanceof DOMElement) {
                $units[$unit->getAttribute('id')] = true;
            }
        }

        $expectedDecimals = $bundle->scale === 0 ? '0' : '2';
        $seen = [];
        $count = 0;

        foreach ($xpath->query('/xbrli:xbrl/t:*') ?: [] as $fact) {
            if (! $fact instanceof DOMElement) {
                continue;
            }

            $count++;
            $name = $fact->prefix.':'.$fact->localName;
            $context = $fact->getAttribute('contextRef');

            if (! isset($contexts[$context])) {
                $add('error', 'schema', 'validation.xbrl_missing_context', ['element' => $name, 'context' => $context]);
            }

            $unit = $fact->getAttribute('unitRef');

            if (! isset($units[$unit])) {
                $add('error', 'schema', 'validation.xbrl_unknown_unit', ['element' => $name, 'unit' => $unit]);
            }

            if ($unit === 'u_money' && $fact->getAttribute('decimals') !== $expectedDecimals) {
                $add('error', 'taxonomy', 'validation.xbrl_decimals', ['element' => $name, 'decimals' => $expectedDecimals]);
            }

            $key = $name.'@'.$context;

            if (isset($seen[$key]) && $seen[$key] !== trim($fact->textContent)) {
                $add('error', 'taxonomy', 'validation.xbrl_duplicate_fact', ['element' => $name, 'context' => $context]);
            }

            $seen[$key] = trim($fact->textContent);
        }

        foreach ([$bundle->balanceSheet, $bundle->incomeStatement] as $catalog) {
            $this->tagging($catalog, $bundle, $add);
        }

        return $count;
    }

    /**
     * Every leaf that carries an amount must be tagged with a taxonomy element.
     *
     * @param  callable(string, string, string, array<string, mixed>=): void  $add
     */
    private function tagging(LineCatalog $catalog, StatementBundle $bundle, callable $add): void
    {
        $amounts = $catalog === $bundle->balanceSheet ? $bundle->quadrature->balanceSheetLeaves : $bundle->quadrature->incomeStatementLeaves;

        foreach ($catalog->leaves() as $code) {
            if ($catalog->get($code)?->xbrlElement === null && ! Money::isZero($amounts[$code] ?? '0')) {
                $add('error', 'taxonomy', 'validation.unmapped_account', ['account' => $code, 'line' => $code]);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $cashFlow
     * @param  callable(string, string, string, array<string, mixed>=): void  $add
     */
    private function statements(StatementBundle $bundle, ?array $cashFlow, callable $add): void
    {
        $bs = $bundle->quadrature->balanceSheetTotals;
        $is = $bundle->quadrature->incomeStatementTotals;
        $assets = $bs[$bundle->codes['bs_assets']] ?? '0';
        $liabilities = $bs[$bundle->codes['bs_liabilities']] ?? '0';

        if (Money::compare($assets, $liabilities) !== 0) {
            $add('error', 'cross_statement', 'validation.balance_equation', ['assets' => $assets, 'liabilities' => $liabilities]);
        }

        $isProfit = $is[$bundle->codes['is_profit']] ?? '0';
        $bsProfit = $bs[$bundle->codes['bs_profit']] ?? '0';

        if (Money::compare($isProfit, $bsProfit) !== 0) {
            $add('error', 'cross_statement', 'validation.net_profit_alignment', ['income_statement' => $isProfit, 'balance_sheet' => $bsProfit]);
        }

        // Every parent line is the sum of its children: recomputing the tree from the leaves must not change any amount.
        foreach ([[$bundle->balanceSheet, $bundle->quadrature->balanceSheetLeaves, $bs, 'balance_sheet'], [$bundle->incomeStatement, $bundle->quadrature->incomeStatementLeaves, $is, 'income_statement']] as [$catalog, $leaves, $totals, $name]) {
            $recomputed = $catalog->totals($leaves);

            foreach ($recomputed as $code => $amount) {
                if (Money::compare($amount, $totals[$code] ?? '0') !== 0) {
                    $add('error', 'cross_statement', 'validation.subtotal_residual', ['statement' => $name, 'residual' => Money::sub($amount, $totals[$code] ?? '0')]);
                    break;
                }
            }
        }

        if ($cashFlow !== null && ! ($cashFlow['reconciled'] ?? false)) {
            $add('error', 'cross_statement', 'validation.cash_reconciliation', ['cash_flow' => (string) ($cashFlow['closing_cash'] ?? '0'), 'balance_sheet' => (string) ($cashFlow['balance_sheet_cash'] ?? '0')]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $notes
     * @param  callable(string, string, string, array<string, mixed>=): void  $add
     */
    private function notes(StatementBundle $bundle, ?array $notes, bool $notesRequired, callable $add): void
    {
        if ($notes === null || $notes === []) {
            if ($notesRequired) {
                $add('warning', 'notes', 'validation.missing_notes');
            }

            return;
        }

        $tolerance = Money::mul((string) config('statutory_financials.tolerance_units', 1), $bundle->scale === 0 ? '1' : '0.01');
        $bs = $bundle->quadrature->balanceSheetTotals;
        $family = $bundle->standard->jurisdiction();
        $fixedLines = $family === 'IT' ? ['it.bs.b'] : ['ch.bs.nca'];
        $fixedTotal = Money::sum(array_map(static fn (string $code): string => $bs[$code] ?? '0', $fixedLines));
        $fixed = $notes['fixed_assets'] ?? null;

        if (is_array($fixed) && ($fixed['fallback'] ?? true) === false) {
            $notesNbv = (string) ($fixed['totals']['net_book_value'] ?? '0');
            $financial = Money::sum(array_map(static fn (string $code): string => $bs[$code] ?? '0', $family === 'IT' ? ['it.bs.b.iii'] : ['ch.bs.fin_assets', 'ch.bs.participations', 'ch.bs.unpaid_capital']));
            $comparable = Money::sub($fixedTotal, $financial);

            if (Money::compare(Money::abs(Money::sub($notesNbv, $comparable)), $tolerance) > 0) {
                $add('warning', 'notes', 'validation.notes_fixed_assets', ['notes' => $notesNbv, 'balance_sheet' => $comparable]);
            }
        }

        $equity = $notes['equity']['totals']['closing'] ?? null;

        if ($equity !== null) {
            $balanceEquity = $bs[$family === 'IT' ? 'it.bs.pn' : 'ch.bs.equity'] ?? '0';

            if (Money::compare(Money::abs(Money::sub((string) $equity, $balanceEquity)), $tolerance) > 0) {
                $add('error', 'notes', 'validation.notes_equity', ['notes' => (string) $equity, 'balance_sheet' => $balanceEquity]);
            }
        }

        $maturity = $notes['maturity'] ?? null;

        if (is_array($maturity) && ($maturity['fallback'] ?? true) === false) {
            $receivables = $bs[$family === 'IT' ? 'it.bs.c.ii' : 'ch.bs.trade_rec'] ?? '0';
            $notesReceivables = (string) ($maturity['receivables']['total'] ?? '0');

            if (Money::compare(Money::abs(Money::sub($notesReceivables, $receivables)), Money::mul($receivables, '0.5')) > 0 && Money::isPositive($receivables)) {
                $add('warning', 'notes', 'validation.notes_receivables', ['notes' => $notesReceivables, 'balance_sheet' => $receivables]);
            }
        }
    }
}
