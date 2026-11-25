<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;
use Modules\ExpenseManagement\Domain\Enums\ReportStatus;
use Modules\ExpenseManagement\Exceptions\ExpenseException;
use Modules\ExpenseManagement\Models\ExpenseAuditLog;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Strategies\FiscalStrategyRegistry;
use Modules\ExpenseManagement\Support\Decimal;
use Modules\HumanResources\Models\Employee;

/**
 * Expense reports: creation, editing, totals, status changes and the audit trail.
 */
final class ReportService
{
    public function __construct(private readonly ExpenseNumbers $numbers, private readonly ExpenseLineService $lines) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $tenantId, string $userId): ExpenseReport
    {
        $this->assertPeriod((string) $data['period_start'], (string) $data['period_end']);
        $tenant = Tenant::query()->find($tenantId);
        $employeeId = $data['employee_id'] ?? Employee::query()->where('user_id', $userId)->value('id');

        return DB::transaction(function () use ($data, $tenantId, $userId, $tenant, $employeeId): ExpenseReport {
            $companyId = $data['company_id'] ?? $tenantId;
            $report = ExpenseReport::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'report_number' => $this->numbers->next($tenantId, $companyId),
                'title' => $data['title'] ?? null,
                'employee_id' => $employeeId,
                'user_id' => $userId,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'jurisdiction' => FiscalStrategyRegistry::jurisdictionOfCountry($tenant?->country),
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'currency' => strtoupper((string) ($data['currency'] ?? $tenant->default_currency ?? 'EUR')),
                'status' => ReportStatus::Draft,
                'created_by' => $userId,
            ]);
            $this->audit($report, 'created', $userId, null, ReportStatus::Draft);

            return $report;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ExpenseReport $report, array $data, string $userId): ExpenseReport
    {
        $this->assertEditable($report);
        $start = (string) ($data['period_start'] ?? $report->period_start->toDateString());
        $end = (string) ($data['period_end'] ?? $report->period_end->toDateString());
        $this->assertPeriod($start, $end);

        $report->fill(array_filter([
            'title' => $data['title'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'period_start' => $start,
            'period_end' => $end,
        ], static fn (mixed $value): bool => $value !== null))->save();
        $this->audit($report, 'updated', $userId);

        return $report;
    }

    public function delete(ExpenseReport $report, string $userId): void
    {
        if ($report->status !== ReportStatus::Draft) {
            throw new ExpenseException('report_not_editable', 409);
        }

        $this->audit($report, 'cancelled', $userId, ReportStatus::Draft, ReportStatus::Cancelled);
        $report->update(['status' => ReportStatus::Cancelled, 'cancelled_at' => now()]);
        $report->delete();
    }

    public function assertEditable(ExpenseReport $report): void
    {
        if (! $report->status->isEditable()) {
            throw new ExpenseException('report_not_editable', 409);
        }
    }

    public function submit(ExpenseReport $report, string $userId): ExpenseReport
    {
        $this->assertCanMove($report, ReportStatus::Submitted);

        $report->load('lines.category', 'perDiems');

        if ($report->lines->isEmpty() && $report->perDiems->isEmpty()) {
            throw new ExpenseException('report_empty');
        }

        foreach ($report->lines as $line) {
            $this->lines->reassess($report, $line);
        }

        $blocking = $report->lines->fresh()->filter(fn (ExpenseLine $line): bool => $line->hasBlockingViolation())->count();

        if ($blocking > 0) {
            throw new ExpenseException('blocking_violations', 422, ['count' => $blocking]);
        }

        return DB::transaction(function () use ($report, $userId): ExpenseReport {
            $from = $report->status;
            $this->recalculate($report);
            $report->update(['status' => ReportStatus::Submitted, 'submitted_at' => now(), 'rejection_reason' => null]);
            $this->audit($report, 'submitted', $userId, $from, ReportStatus::Submitted);

            return $report;
        });
    }

    public function startReview(ExpenseReport $report, string $userId): ExpenseReport
    {
        $this->assertCanMove($report, ReportStatus::UnderReview);
        $report->update(['status' => ReportStatus::UnderReview]);
        $this->audit($report, 'review_started', $userId, ReportStatus::Submitted, ReportStatus::UnderReview);

        return $report;
    }

    public function approve(ExpenseReport $report, string $userId, ?string $approvedAmount, ?string $comment): ExpenseReport
    {
        $this->assertCanMove($report, ReportStatus::Approved);
        $this->recalculate($report);

        $amount = $approvedAmount !== null ? Decimal::normalize($approvedAmount) : $report->reimbursable_amount;

        if (Decimal::cmp($amount, $report->reimbursable_amount) > 0 || Decimal::cmp($amount, '0') < 0) {
            throw new ExpenseException('approved_amount_exceeds', 422, ['max' => $report->reimbursable_amount]);
        }

        $from = $report->status;
        $report->update(['status' => ReportStatus::Approved, 'approved_amount' => $amount, 'approved_by' => $userId, 'approved_at' => now()]);
        $this->audit($report, 'approved', $userId, $from, ReportStatus::Approved, ['approved_amount' => $amount, 'comment' => $comment]);

        return $report;
    }

    public function reject(ExpenseReport $report, string $userId, string $reason): ExpenseReport
    {
        if (trim($reason) === '') {
            throw new ExpenseException('rejection_reason_required');
        }

        $this->assertCanMove($report, ReportStatus::Rejected);
        $from = $report->status;
        $report->update(['status' => ReportStatus::Rejected, 'rejection_reason' => $reason, 'approved_by' => null, 'approved_at' => null]);
        $this->audit($report, 'rejected', $userId, $from, ReportStatus::Rejected, ['reason' => $reason]);

        return $report;
    }

    public function cancel(ExpenseReport $report, string $userId): ExpenseReport
    {
        $this->assertCanMove($report, ReportStatus::Cancelled);
        $from = $report->status;
        $report->update(['status' => ReportStatus::Cancelled, 'cancelled_at' => now()]);
        $this->audit($report, 'cancelled', $userId, $from, ReportStatus::Cancelled);

        return $report;
    }

    public function assertCanMove(ExpenseReport $report, ReportStatus $target): void
    {
        if (! $report->status->canMoveTo($target)) {
            throw new ExpenseException('invalid_transition', 409, ['from' => $report->status->value, 'to' => $target->value]);
        }
    }

    /**
     * Totals of the report from its lines and per-diem allowances. Lines paid by a corporate card are not reimbursed; the
     * taxable amount is what payroll must tax (non-traceable payments and per-diem excess).
     */
    public function recalculate(ExpenseReport $report): ExpenseReport
    {
        $lines = ExpenseLine::query()->where('expense_report_id', $report->id)->get();
        $perDiems = $report->perDiems()->get();

        $total = '0.00';
        $reimbursable = '0.00';
        $taxable = '0.00';

        foreach ($lines as $line) {
            $total = Decimal::add($total, $line->amount_in_base_currency);

            if ($line->payment_method === PaymentMethod::EmployeeOutOfPocket && $line->is_reimbursable) {
                $reimbursable = Decimal::add($reimbursable, $line->amount_in_base_currency);

                if ($line->is_taxable_benefit) {
                    $taxable = Decimal::add($taxable, $line->amount_in_base_currency);
                }
            }
        }

        foreach ($perDiems as $allowance) {
            $total = Decimal::add($total, $allowance->gross_allowance_amount);
            $reimbursable = Decimal::add($reimbursable, $allowance->gross_allowance_amount);
            $taxable = Decimal::add($taxable, $allowance->taxable_excess_amount);
        }

        $report->forceFill([
            'total_amount' => $total,
            'reimbursable_amount' => $reimbursable,
            'non_reimbursable_amount' => Decimal::sub($total, $reimbursable),
            'taxable_amount' => $taxable,
        ])->save();

        return $report;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function audit(ExpenseReport $report, string $action, ?string $actorId, ?ReportStatus $from = null, ?ReportStatus $to = null, array $payload = [], ?string $lineId = null): void
    {
        ExpenseAuditLog::create([
            'tenant_id' => $report->tenant_id ?? app(TenantContext::class)->getTenantId(),
            'expense_report_id' => $report->id,
            'expense_line_id' => $lineId,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_id' => $actorId,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }

    private function assertPeriod(string $start, string $end): void
    {
        if ($end < $start) {
            throw new ExpenseException('period_invalid');
        }
    }
}
