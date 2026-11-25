<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Modules\ExpenseManagement\Models\ExpenseReport;

/**
 * An expense report; the detail adds the lines, per-diem allowances, audit trail and payouts when they are loaded.
 *
 * @mixin ExpenseReport
 */
final class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $lines = $this->relationLoaded('lines') ? $this->lines : null;

        return [
            'id' => $this->id,
            'report_number' => $this->report_number,
            'title' => $this->title,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('claimant', fn (): ?array => $this->claimant === null ? null : ['id' => $this->claimant->id, 'name' => $this->claimant->name]),
            'user_id' => $this->user_id,
            'cost_center_id' => $this->cost_center_id,
            'company_id' => $this->company_id,
            'jurisdiction' => $this->jurisdiction,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'total_amount' => $this->total_amount,
            'approved_amount' => $this->approved_amount,
            'reimbursable_amount' => $this->reimbursable_amount,
            'non_reimbursable_amount' => $this->non_reimbursable_amount,
            'taxable_amount' => $this->taxable_amount,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'reimbursed_at' => $this->reimbursed_at?->toIso8601String(),
            'violations_count' => $lines !== null
                ? $lines->filter(static fn ($line): bool => ($line->policy_violations ?? []) !== [])->count()
                : (int) ($this->violations_count ?? 0),
            'blocking_violations_count' => $lines?->filter(static fn ($line): bool => $line->hasBlockingViolation())->count() ?? 0,
            'abilities' => $user === null ? null : [
                'update' => $this->status->isEditable() && Gate::forUser($user)->allows('update', $this->resource),
                'submit' => $this->status->isEditable() && Gate::forUser($user)->allows('update', $this->resource),
                'approve' => in_array($this->status->value, ['submitted', 'under_review'], true) && Gate::forUser($user)->allows('approve', $this->resource),
                'settle' => in_array($this->status->value, ['approved', 'posted'], true) && Gate::forUser($user)->allows('settle', $this->resource),
            ],
            'lines' => LineResource::collection($this->whenLoaded('lines')),
            'per_diems' => PerDiemResource::collection($this->whenLoaded('perDiems')),
            'audit' => AuditLogResource::collection($this->whenLoaded('auditLogs')),
            'payouts' => PayoutResource::collection($this->whenLoaded('payouts')),
        ];
    }
}
