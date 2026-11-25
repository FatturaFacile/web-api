<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

use Modules\GeneralLedger\Models\FiscalYear;
use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Enums\DossierStatus;
use Modules\StatutoryFinancials\Domain\Enums\IncomeStatementMethod;
use Modules\StatutoryFinancials\Domain\Enums\RoundingMode;
use Modules\StatutoryFinancials\Models\RatioDefinition;
use Modules\StatutoryFinancials\Models\TextTemplate;
use Modules\StatutoryFinancials\Services\TextTemplateService;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;

final class GetLookupsHandler
{
    public function __construct(private readonly StatutoryStrategyResolver $strategies, private readonly TextTemplateService $templates) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetLookupsQuery $query): array
    {
        $locale = $query->locale;

        $standards = array_map(function (AccountingStandard $standard) use ($locale): array {
            $strategy = $this->strategies->for($standard);
            $text = $this->templates->render('standard.'.$standard->value, $locale);

            return [
                'value' => $standard->value,
                'jurisdiction' => $standard->jurisdiction(),
                'scheme' => $standard->scheme(),
                'legal_reference' => $standard->legalReference(),
                'label' => $text['title'],
                'description' => $text['body'],
                'income_statement_methods' => array_map(static fn (IncomeStatementMethod $method): string => $method->value, $strategy->incomeStatementMethods($standard)),
                'cash_flow_methods' => array_map(static fn (CashFlowMethod $method): string => $method->value, $strategy->cashFlowMethods($standard)),
                'cash_flow_label' => $strategy->cashFlowLabelKey(),
                'pdfa_profile' => $strategy->pdfAProfile(),
                'notes' => $strategy->noteStructure($standard),
            ];
        }, AccountingStandard::cases());

        $labels = [];

        foreach (TextTemplate::query()->whereIn('kind', ['report_label', 'eligibility_message'])->get() as $template) {
            $labels[$template->template_key] = ['title' => $template->title($locale), 'body' => $template->body($locale)];
        }

        $narratives = array_map(static fn (TextTemplate $template): array => [
            'key' => $template->template_key,
            'family' => $template->family,
            'title' => $template->title($locale),
            'body' => $template->body($locale),
            'placeholders' => $template->placeholders ?? [],
        ], TextTemplate::query()->whereIn('kind', ['narrative_section', 'management_report', 'minutes'])->orderBy('sort_order')->get()->all());

        $ratios = array_map(static fn (RatioDefinition $definition): array => [
            'code' => $definition->code,
            'family' => $definition->family,
            'unit' => $definition->unit,
            'direction' => $definition->direction,
            'warning_threshold' => $definition->warning_threshold,
            'critical_threshold' => $definition->critical_threshold,
            'is_crisis_indicator' => $definition->is_crisis_indicator,
            'label' => $definition->labels[$locale] ?? $definition->labels['en'] ?? $definition->code,
            'description' => $definition->descriptions[$locale] ?? $definition->descriptions['en'] ?? '',
        ], RatioDefinition::query()->orderBy('sort_order')->get()->all());

        return [
            'locale' => $locale,
            'standards' => $standards,
            'rounding_modes' => array_map(static fn (RoundingMode $mode): array => ['value' => $mode->value, 'scale' => $mode->scale()], RoundingMode::cases()),
            'statuses' => DossierStatus::values(),
            'fiscal_years' => FiscalYear::query()->orderByDesc('start_date')->get(['id', 'year_code', 'start_date', 'end_date', 'status'])->map(static fn (FiscalYear $year): array => [
                'id' => $year->id,
                'year_code' => $year->year_code,
                'start_date' => $year->start_date?->toDateString(),
                'end_date' => $year->end_date?->toDateString(),
                'status' => $year->status,
            ])->all(),
            'labels' => $labels,
            'narrative_templates' => $narratives,
            'ratios' => $ratios,
            'what_if_parameters' => ['ebitda_change_percent', 'revenue_change_percent', 'debt_service_change_percent', 'extra_liquidity', 'expected_cash_flow_6m', 'debt_service_6m', 'opening_liquidity'],
        ];
    }
}
