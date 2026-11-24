<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intrastat\Enums\IntrastatFlow;
use Modules\Intrastat\Models\IntrastatDeclaration;

/**
 * A list with its frontespizio, the totals per section and, when loaded, its detail rows.
 *
 * @mixin IntrastatDeclaration
 */
final class DeclarationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flow' => $this->flow->value,
            'model' => $this->flow === IntrastatFlow::Sales ? 'INTRA-1' : 'INTRA-2',
            'year' => $this->year,
            'periodicity' => $this->periodicity->value,
            'period' => $this->period,
            'reference_number' => $this->reference_number,
            'content_code' => $this->content_code,
            'special_case_code' => $this->special_case_code,
            'obligated_vat_number' => $this->obligated_vat_number,
            'delegate_vat_number' => $this->delegate_vat_number,
            'includes_statistical_data' => $this->includes_statistical_data,
            'has_annual_flows_above_20_million' => $this->has_annual_flows_above_20_million,
            'status' => $this->status->value,
            'validated_at' => $this->validated_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'protocol_number' => $this->protocol_number,
            'notes' => $this->notes,
            'last_export_file_name' => $this->last_export_file_name,
            'last_exported_at' => $this->last_exported_at?->toIso8601String(),
            'generation_report' => $this->generation_report,
            'sections' => $this->whenLoaded('lines', fn (): array => collect([1, 2, 3, 4])->map(fn (int $section): array => [
                'section' => $section,
                'count' => $this->lines->where('section', $section)->count(),
                'total_eur' => $this->lines->where('section', $section)->sum(static fn ($line): int => $section === 2 && $line->sign === '-' ? -((int) $line->amount_eur) : (int) $line->amount_eur),
            ])->all()),
            'lines' => DeclarationLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
