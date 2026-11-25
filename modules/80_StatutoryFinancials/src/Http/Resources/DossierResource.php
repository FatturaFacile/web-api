<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\StatutoryFinancials\Models\StatutoryDossier;

/**
 * Summary of a dossier for lists.
 *
 * @mixin StatutoryDossier
 */
final class DossierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $year = $this->relationLoaded('fiscalYear') ? $this->fiscalYear : null;
        $package = $this->relationLoaded('package') ? $this->package : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'fiscal_year_id' => $this->fiscal_year_id,
            'fiscal_year' => $year === null ? null : ['id' => $year->id, 'year_code' => $year->year_code, 'start_date' => $year->start_date?->toDateString(), 'end_date' => $year->end_date?->toDateString()],
            'comparative_prior_year_id' => $this->comparative_prior_year_id,
            'accounting_standard' => $this->accounting_standard->value,
            'status' => $this->status->value,
            'rounding_to_unit_mode' => $this->rounding_to_unit_mode->value,
            'income_statement_method' => $this->income_statement_method->value,
            'cash_flow_method' => $this->cash_flow_method->value,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'has_statements' => $this->balance_sheet_lines !== null,
            'validation_status' => $package?->validation_status,
            'net_result' => $this->income_statement_lines['net_result'] ?? null,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'filed_at' => $this->filed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
