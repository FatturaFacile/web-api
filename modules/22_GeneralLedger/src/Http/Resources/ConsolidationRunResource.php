<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GeneralLedger\Models\ConsolidationAdjustment;
use Modules\GeneralLedger\Models\ConsolidationAdjustmentLine;
use Modules\GeneralLedger\Models\ConsolidationRun;

/**
 * @mixin ConsolidationRun
 */
final class ConsolidationRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'group_id' => (string) $this->group_id,
            'fiscal_period_id' => (string) $this->fiscal_period_id,
            'version' => (int) $this->version,
            'status' => $this->status,
            'group_currency' => $this->group_currency,
            'year_start' => $this->year_start->toDateString(),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'input_fingerprint' => $this->input_fingerprint,
            'members' => $this->members_snapshot,
            'rates' => $this->rates,
            'warnings' => $this->warnings,
            'totals' => $this->totals,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'locked_by' => $this->locked_by,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'adjustments' => $this->whenLoaded('adjustments', fn (): array => $this->adjustments->map(static fn (ConsolidationAdjustment $adjustment): array => [
                'id' => (string) $adjustment->id,
                'adjustment_type' => $adjustment->adjustment_type->value,
                'relation_id' => $adjustment->relation_id,
                'member_tenant_id' => $adjustment->member_tenant_id,
                'description' => $adjustment->description,
                'amount' => (string) $adjustment->amount,
                'lines' => $adjustment->lines->map(static fn (ConsolidationAdjustmentLine $line): array => [
                    'entity_tenant_id' => $line->entity_tenant_id,
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'account_type' => $line->account_type,
                    'debit' => (string) $line->debit,
                    'credit' => (string) $line->credit,
                ])->all(),
            ])->all()),
        ];
    }
}
