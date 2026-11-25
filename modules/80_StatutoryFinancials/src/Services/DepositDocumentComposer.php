<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Modules\StatutoryFinancials\Domain\Statement\LineDefinition;
use Modules\StatutoryFinancials\Domain\Statement\StatementBundle;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Pdf\PdfADocument;
use Modules\StatutoryFinancials\Pdf\PdfADocumentFactory;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;
use Modules\StatutoryFinancials\Support\AmountFormatter;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Composes the PDF/A documents of the deposit package: the management report (Relazione sulla Gestione / Lagebericht), the board
 * and shareholders minutes (Verbale CdA / Verbale di Assemblea) and the consolidated dossier. All wording comes from the seeded
 * text templates; the figures come from the generated statements of the dossier.
 */
final class DepositDocumentComposer
{
    public function __construct(
        private readonly PdfADocumentFactory $documents,
        private readonly TextTemplateService $templates,
        private readonly NotesService $notes,
        private readonly StatutoryStrategyResolver $strategies,
        private readonly LineCatalogRepository $catalogs,
    ) {}

    /**
     * @param  array<string, mixed>  $indicators  Result of the strategy indicators().
     */
    public function managementReport(StatutoryDossier $dossier, StatementBundle $bundle, array $indicators): string
    {
        $locale = $dossier->locale;
        $params = $this->notes->placeholders($dossier, $bundle);
        $document = $this->start($dossier, $bundle, $this->templates->title('mr.intro', $locale), $locale);
        $document->heading($this->templates->title('mr.intro', $locale));

        foreach (['mr.intro', 'mr.performance', 'mr.financial_position', 'mr.risks', 'mr.outlook'] as $key) {
            $rendered = $this->templates->render($key, $locale, $params);

            if ($key !== 'mr.intro') {
                $document->heading($rendered['title'], 2);
            }

            $document->paragraph($rendered['body']);
        }

        $document->heading($this->label('report.ratios', $locale), 2);

        foreach ($indicators['indicators'] ?? [] as $indicator) {
            $document->row((string) $indicator['label'], [$indicator['value'] === null ? '-' : $this->indicatorValue($indicator, $bundle)]);

            if (! empty($indicator['message'])) {
                $document->paragraph((string) $indicator['message'], 8.5);
            }
        }

        return $document->render();
    }

    /**
     * @param  array{meeting_date: string, meeting_place: string, chair: string, secretary: string, attendees: list<string>}  $meeting
     */
    public function minutes(StatutoryDossier $dossier, StatementBundle $bundle, string $kind, array $meeting): string
    {
        $strategy = $this->strategies->for($dossier->accounting_standard);
        $key = $strategy->minutesTemplates()[$kind];
        $locale = $dossier->locale;
        $params = array_merge($this->notes->placeholders($dossier, $bundle), [
            'meeting_date' => $meeting['meeting_date'],
            'meeting_place' => $meeting['meeting_place'],
            'chair' => $meeting['chair'],
            'secretary' => $meeting['secretary'],
            'attendees' => implode(', ', $meeting['attendees']),
            'allocation' => $this->allocationText($dossier, $bundle, $locale),
        ]);
        $rendered = $this->templates->render($key, $locale, $params);
        $document = $this->start($dossier, $bundle, $rendered['title'], $locale);
        $document->heading($rendered['title'])->paragraph($rendered['body']);

        return $document->render();
    }

    /**
     * @param  array<string, mixed>|null  $cashFlow
     * @param  array<string, mixed>|null  $notes  {tables, sections}
     * @param  array<string, mixed>|null  $indicators
     * @param  array<string, mixed>|null  $validation
     */
    public function dossier(StatutoryDossier $dossier, StatementBundle $bundle, ?array $cashFlow, ?array $notes, ?array $indicators, ?array $validation): string
    {
        $locale = $dossier->locale;
        $family = $bundle->standard->jurisdiction();
        $title = $this->label('report.dossier_title', $locale);
        $document = $this->start($dossier, $bundle, $title, $locale);
        $params = $this->notes->placeholders($dossier, $bundle);
        $document->heading($title.' - '.$params['company_name'])->paragraph($bundle->standard->legalReference().' - '.$params['fiscal_year'].' - '.$params['currency']);
        $columns = $bundle->comparativeBalanceSheet !== null ? [$this->label('report.current', $locale), $this->label('report.comparative', $locale)] : [$this->label('report.current', $locale)];

        $document->heading($this->label('report.balance_sheet', $locale), 2)->row('', $columns, true);
        $this->statement($document, $bundle->balanceSheet->lines(), $bundle->quadrature->balanceSheetTotals, $bundle->comparativeBalanceSheet, $bundle->scale, $family, $locale);
        $document->pageBreak()->heading($this->label('report.income_statement', $locale), 2)->row('', $columns, true);
        $this->statement($document, $bundle->incomeStatement->lines(), $bundle->quadrature->incomeStatementTotals, $bundle->comparativeIncomeStatement, $bundle->scale, $family, $locale);

        if ($cashFlow !== null) {
            $catalog = $this->catalogs->catalog($bundle->standard, 'cash_flow', (string) $cashFlow['method']);
            $document->pageBreak()->heading($this->label('report.cash_flow', $locale).' ('.$this->strategies->for($bundle->standard)->cashFlowLabelKey().')', 2);
            $this->statement($document, $catalog->lines(), $cashFlow['totals'], null, $bundle->scale, $family, $locale);
        }

        if ($notes !== null) {
            $document->pageBreak()->heading($this->label($family === 'IT' ? 'report.notes_it' : 'report.notes_ch', $locale), 2);

            foreach ($notes['sections'] ?? [] as $section) {
                $document->heading((string) $section['title'], 3)->paragraph((string) $section['body']);
            }

            $this->noteTables($document, (array) ($notes['tables'] ?? []), $bundle, $family);
        }

        $allocation = $dossier->profit_allocation_proposal;

        if (is_array($allocation) && $allocation !== []) {
            $document->heading($this->label('report.profit_allocation', $locale), 2)->paragraph($this->allocationText($dossier, $bundle, $locale));
        }

        if ($indicators !== null) {
            $document->pageBreak()->heading($this->label('report.ratios', $locale), 2);

            foreach ($indicators['indicators'] ?? [] as $indicator) {
                $document->row((string) $indicator['label'], [$indicator['value'] === null ? '-' : $this->indicatorValue($indicator, $bundle)]);
            }

            foreach ($indicators['alerts'] ?? [] as $alert) {
                $document->paragraph((string) $alert['message'], 8.5);
            }
        }

        if ($validation !== null) {
            $document->heading($this->label('report.validation', $locale), 2)->paragraph(strtoupper((string) ($validation['status'] ?? '')));

            foreach (array_merge($validation['errors'] ?? [], $validation['warnings'] ?? []) as $issue) {
                $document->paragraph('- '.(string) $issue['message'], 8.5);
            }
        }

        return $document->render();
    }

    private function start(StatutoryDossier $dossier, StatementBundle $bundle, string $title, string $locale): PdfADocument
    {
        $params = $this->notes->placeholders($dossier, $bundle);
        $profile = $this->strategies->for($dossier->accounting_standard)->pdfAProfile();

        return $this->documents->make($profile, $title.' - '.$params['company_name'].' '.$params['fiscal_year'], $params['company_name'], $bundle->standard->legalReference(), $locale);
    }

    /**
     * @param  array<string, LineDefinition>  $lines
     * @param  array<string, string>  $amounts
     * @param  array<string, string>|null  $comparative
     */
    private function statement(PdfADocument $document, array $lines, array $amounts, ?array $comparative, int $scale, string $family, string $locale): void
    {
        $levels = [];

        foreach ($lines as $code => $line) {
            $level = $line->parentCode !== null && isset($levels[$line->parentCode]) ? $levels[$line->parentCode] + 1 : 0;
            $levels[$code] = $level;
            $values = [AmountFormatter::format($amounts[$code] ?? '0', $scale, $family)];

            if ($comparative !== null) {
                $values[] = AmountFormatter::format($comparative[$code] ?? '0', $scale, $family);
            }

            $document->row($line->label($locale), $values, $level === 0, min($level, 3));
        }
    }

    /**
     * @param  array<string, mixed>  $tables
     */
    private function noteTables(PdfADocument $document, array $tables, StatementBundle $bundle, string $family): void
    {
        foreach ($tables['fixed_assets']['rows'] ?? [] as $row) {
            $document->row((string) $row['category'], [AmountFormatter::format((string) $row['closing_cost'], $bundle->scale, $family), AmountFormatter::format((string) $row['net_book_value'], $bundle->scale, $family)]);
        }

        foreach ($tables['equity']['rows'] ?? [] as $row) {
            $document->row((string) $row['label'], [AmountFormatter::format((string) $row['opening'], $bundle->scale, $family), AmountFormatter::format((string) $row['closing'], $bundle->scale, $family)]);
        }
    }

    private function allocationText(StatutoryDossier $dossier, StatementBundle $bundle, string $locale): string
    {
        $allocation = $dossier->profit_allocation_proposal;
        $family = $bundle->standard->jurisdiction();

        if (! is_array($allocation) || $allocation === []) {
            return $this->label('allocation.none', $locale);
        }

        $format = fn (string $value): string => AmountFormatter::format($value, $bundle->scale, $family);
        $parts = [];

        foreach (['legal_reserve_allocation' => 'allocation.legal_reserve', 'dividends' => 'allocation.dividends', 'other_reserves' => 'allocation.other_reserves'] as $field => $label) {
            if (Money::isPositive((string) ($allocation[$field] ?? '0'))) {
                $parts[] = $this->label($label, $locale).' '.$format((string) $allocation[$field]);
            }
        }

        $carried = (string) ($allocation['carried_forward_out'] ?? '0');
        $parts[] = $this->label(Money::isNegative($carried) ? 'allocation.loss_carried' : 'allocation.carried_forward', $locale).' '.$format($carried);

        return implode('; ', $parts);
    }

    /**
     * @param  array<string, mixed>  $indicator
     */
    private function indicatorValue(array $indicator, StatementBundle $bundle): string
    {
        $suffix = $indicator['unit'] === 'percent' ? ' %' : '';
        $scale = $indicator['unit'] === 'amount' ? $bundle->scale : 2;

        return AmountFormatter::format((string) $indicator['value'], $scale, $bundle->standard->jurisdiction()).$suffix;
    }

    private function label(string $key, string $locale): string
    {
        return $this->templates->title($key, $locale);
    }
}
