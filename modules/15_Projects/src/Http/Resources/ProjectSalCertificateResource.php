<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectSalCertificate;

/**
 * A SAL progress certificate (Mod 081).
 *
 * @mixin ProjectSalCertificate
 */
final class ProjectSalCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectSalCertificate $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'project_id' => $m->project_id,
            'billing_contract_id' => $m->billing_contract_id,
            'sal_number' => (int) $m->sal_number,
            'period_start_date' => $m->period_start_date->toDateString(),
            'period_end_date' => $m->period_end_date->toDateString(),
            'previous_completion_percent' => (string) $m->previous_completion_percent,
            'cumulative_completion_percent' => (string) $m->cumulative_completion_percent,
            'cumulative_gross_earned_value' => (string) $m->cumulative_gross_earned_value,
            'previous_sal_cumulative_billed' => (string) $m->previous_sal_cumulative_billed,
            'current_period_gross_sal' => (string) $m->current_period_gross_sal,
            'advance_deduction_amount' => (string) $m->advance_deduction_amount,
            'guarantee_retention_withheld' => (string) $m->guarantee_retention_withheld,
            'invoice_amount' => (string) $m->invoice_amount,
            'net_billable_amount' => (string) $m->net_billable_amount,
            'is_final' => (bool) $m->is_final,
            'status' => $m->status,
            'customer_approved_at' => $m->customer_approved_at?->toIso8601String(),
            'customer_approved_by' => $m->customer_approved_by,
            'sales_invoice_id' => $m->sales_invoice_id,
            'notes' => $m->notes,
        ];
    }
}
