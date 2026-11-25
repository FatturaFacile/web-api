<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\StatutoryFinancials\Domain\Statement\LineDefinition;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\LineCatalogRepository;
use Modules\StatutoryFinancials\Services\TextTemplateService;

/**
 * The full state of a dossier: statements with seeded line labels, the rounding quadrature lines, the reclassification report
 * (bifronte accounts, unmapped accounts, warnings with their seeded wording), notes, package and artifacts.
 *
 * @mixin StatutoryDossier
 */
final class DossierDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = (new DossierResource($this->resource))->toArray($request);
        $definitions = app(LineCatalogRepository::class)->allDefinitions($this->accounting_standard);
        $templates = app(TextTemplateService::class);
        $locale = $this->locale;
        $report = $this->reclassification_report ?? [];
        $quadrature = $this->quadrature ?? [];

        $warnings = array_map(static fn (array $warning): array => $warning + ['message' => $templates->render($warning['code'], $locale, $warning['params'])['body']], (array) ($report['warnings'] ?? []));

        return array_merge($summary, [
            'share_capital' => $this->share_capital,
            'options' => $this->options ?? new \stdClass,
            'statements' => [
                'balance_sheet' => $this->statement($this->balance_sheet_lines, $definitions, $locale),
                'income_statement' => $this->statement($this->income_statement_lines, $definitions, $locale),
                'cash_flow' => $this->statement($this->cash_flow_statement, $definitions, $locale),
            ],
            'quadrature' => $quadrature === [] ? null : [
                'scale' => $quadrature['scale'] ?? 2,
                'rounding_reserve' => $quadrature['rounding_reserve'] ?? '0',
                'rounding_income' => $quadrature['rounding_income'] ?? '0',
                'rounding_cost' => $quadrature['rounding_cost'] ?? '0',
                'balance_sheet_residual' => $quadrature['balance_sheet_residual'] ?? '0',
                'income_statement_residual' => $quadrature['income_statement_residual'] ?? '0',
                'reserve_line_code' => $quadrature['codes']['bs_reserve'] ?? null,
                'income_line_code' => $quadrature['codes']['is_round_income'] ?? null,
                'cost_line_code' => $quadrature['codes']['is_round_cost'] ?? null,
            ],
            'reclassification' => [
                'bifront' => array_map(static fn (array $row): array => $row + ['line_label' => ($definitions[$row['line']] ?? null)?->label($locale)], (array) ($report['bifront'] ?? [])),
                'unmapped' => array_map(static fn (array $row): array => $row + ['line_label' => ($definitions[$row['line']] ?? null)?->label($locale)], (array) ($report['unmapped'] ?? [])),
                'warnings' => $warnings,
            ],
            'eligibility' => $this->eligibility,
            'profit_allocation_proposal' => $this->profit_allocation_proposal,
            'hidden_reserves_config' => $this->hidden_reserves_config,
            'cash_flow_statement' => $this->cash_flow_statement === null ? null : ['method' => $this->cash_flow_statement['method'], 'reconciled' => $this->cash_flow_statement['reconciled'] ?? null, 'standard_label' => $this->cash_flow_statement['standard_label'] ?? null],
            'notes' => $this->relationLoaded('notes') && $this->notes !== null ? (new NotesResource($this->notes))->toArray($request) : null,
            'package' => $this->relationLoaded('package') && $this->package !== null ? (new PackageResource($this->package))->toArray($request) : null,
            'artifacts' => $this->relationLoaded('artifacts') ? ArtifactResource::collection($this->artifacts)->toArray($request) : [],
            'filing_reference' => $this->filing_reference,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $statement
     * @param  array<string, LineDefinition>  $definitions
     * @return array<string, mixed>|null
     */
    private function statement(?array $statement, array $definitions, string $locale): ?array
    {
        if ($statement === null) {
            return null;
        }

        $lines = array_map(static fn (array $line): array => $line + ['label' => ($definitions[$line['code']] ?? null)?->label($locale) ?? $line['code']], (array) ($statement['lines'] ?? []));

        return array_merge(array_diff_key($statement, ['lines' => true, 'exact' => true, 'leaves' => true]), ['lines' => $lines]);
    }
}
