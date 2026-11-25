<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Statement\LineCatalog;
use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\LineCatalogRepository;
use Modules\StatutoryFinancials\Services\StatementPersistence;
use Modules\StatutoryFinancials\Services\TextTemplateService;
use Modules\StatutoryFinancials\Services\XbrlInstanceBuilder;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;

final class GetMinisterialPreviewHandler
{
    private const int ExcerptLength = 12000;

    public function __construct(
        private readonly StatementPersistence $persistence,
        private readonly LineCatalogRepository $catalogs,
        private readonly XbrlInstanceBuilder $xbrl,
        private readonly StatutoryStrategyResolver $strategies,
        private readonly TextTemplateService $templates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetMinisterialPreviewQuery $query): array
    {
        $dossier = StatutoryDossier::query()->with(['notes', 'package'])->findOrFail($query->dossierId);

        if ($dossier->balance_sheet_lines === null) {
            throw new StatutoryFinancialsException('statements_not_generated', 422);
        }

        $locale = $dossier->locale;
        $bundle = $this->persistence->rehydrate($dossier);
        $strategy = $this->strategies->for($dossier->accounting_standard);
        $profile = $strategy->xbrlProfile($dossier->accounting_standard);
        $cashFlow = $dossier->cash_flow_statement;
        $cashFlowCatalog = $cashFlow !== null ? $this->catalogs->catalog($dossier->accounting_standard, 'cash_flow', (string) ($cashFlow['method'] ?? CashFlowMethod::Indirect->value)) : null;
        $notes = $dossier->notes?->quantitative_tables;

        $sections = [
            ['statement' => 'balance_sheet', 'title' => $this->templates->title('report.balance_sheet', $locale), 'rows' => $this->rows($bundle->balanceSheet, $bundle->quadrature->balanceSheetTotals, $bundle->comparativeBalanceSheet, $locale)],
            ['statement' => 'income_statement', 'title' => $this->templates->title('report.income_statement', $locale), 'rows' => $this->rows($bundle->incomeStatement, $bundle->quadrature->incomeStatementTotals, $bundle->comparativeIncomeStatement, $locale)],
        ];

        if ($cashFlow !== null && $cashFlowCatalog !== null) {
            $sections[] = ['statement' => 'cash_flow', 'title' => $this->templates->title('report.cash_flow', $locale), 'rows' => $this->rows($cashFlowCatalog, $cashFlow['totals'], null, $locale)];
        }

        $xml = $this->xbrl->build($dossier, $bundle, $profile, $cashFlow, $cashFlowCatalog, $notes);

        return [
            'title' => $this->templates->title('report.ministerial_preview', $locale),
            'standard' => $dossier->accounting_standard->value,
            'legal_reference' => $dossier->accounting_standard->legalReference(),
            'scale' => $bundle->scale,
            'currency' => $dossier->currency,
            'taxonomy' => ['version' => $profile->taxonomyVersion, 'prefix' => $profile->prefix, 'namespace' => $profile->namespace, 'entry_point' => $profile->entryPoint],
            'has_comparative' => $bundle->comparativeBalanceSheet !== null,
            'sections' => $sections,
            'notes' => $dossier->notes === null ? null : ['tables' => $dossier->notes->quantitative_tables, 'sections' => $dossier->notes->narrative_text_sections],
            'validation' => $dossier->package?->tebeni_validation_report,
            'xbrl_excerpt' => mb_substr($xml, 0, self::ExcerptLength),
            'xbrl_truncated' => mb_strlen($xml) > self::ExcerptLength,
        ];
    }

    /**
     * @param  array<string, string>  $amounts
     * @param  array<string, string>|null  $comparative
     * @return list<array<string, mixed>>
     */
    private function rows(LineCatalog $catalog, array $amounts, ?array $comparative, string $locale): array
    {
        $rows = [];
        $levels = [];

        foreach ($catalog->lines() as $code => $line) {
            $level = $line->parentCode !== null && isset($levels[$line->parentCode]) ? $levels[$line->parentCode] + 1 : 0;
            $levels[$code] = $level;
            $rows[] = [
                'code' => $code,
                'label' => $line->label($locale),
                'level' => $level,
                'is_total' => ! $catalog->isLeaf($code),
                'is_rounding' => $line->isRounding,
                'xbrl_element' => $line->xbrlElement,
                'amount' => $amounts[$code] ?? '0',
                'comparative_amount' => $comparative !== null ? ($comparative[$code] ?? '0') : null,
            ];
        }

        return $rows;
    }
}
