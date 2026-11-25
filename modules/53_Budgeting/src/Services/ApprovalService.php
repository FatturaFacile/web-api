<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Models\BudgetApproval;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * Approval workflow of a budget version: draft, submitted, approved, locked. The approval trail is kept in the module
 * (the module does not depend on the workflow engine). A company has one approved or locked budget per fiscal year;
 * forecasts and rolling forecasts follow the same steps without that limit.
 */
final readonly class ApprovalService
{
    public function submit(BudgetVersion $version, ?string $userId, ?string $comment): BudgetVersion
    {
        return $this->transition($version, [BudgetVersion::Draft], BudgetVersion::Submitted, BudgetApproval::Submit, $userId, $comment, function (BudgetVersion $locked) use ($userId): void {
            if (! BudgetLine::query()->where('version_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['status' => [__('budgeting_errors.approval.empty')]]);
            }

            $locked->submitted_by = $userId;
            $locked->submitted_at = now();
        });
    }

    public function approve(BudgetVersion $version, ?string $userId, ?string $comment): BudgetVersion
    {
        return $this->transition($version, [BudgetVersion::Submitted], BudgetVersion::Approved, BudgetApproval::Approve, $userId, $comment, function (BudgetVersion $locked) use ($userId): void {
            if ($locked->version_type === BudgetVersion::Budget) {
                $other = BudgetVersion::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('fiscal_year', $locked->fiscal_year)
                    ->where('version_type', BudgetVersion::Budget)
                    ->whereIn('status', [BudgetVersion::Approved, BudgetVersion::Locked])
                    ->whereKeyNot($locked->id)
                    ->first();

                if ($other !== null) {
                    throw ValidationException::withMessages(['status' => [__('budgeting_errors.approval.budget_exists', ['name' => $other->name, 'year' => $locked->fiscal_year])]]);
                }
            }

            if ($locked->version_type === BudgetVersion::Revised) {
                $baseline = $locked->base_version_id === null ? null : BudgetVersion::query()->find($locked->base_version_id);

                if ($baseline === null || ! in_array($baseline->status, [BudgetVersion::Approved, BudgetVersion::Locked], true)) {
                    throw ValidationException::withMessages(['status' => [__('budgeting_errors.approval.baseline_not_approved')]]);
                }
            }

            $locked->approved_by = $userId;
            $locked->approved_at = now();
        });
    }

    public function reject(BudgetVersion $version, ?string $userId, ?string $comment): BudgetVersion
    {
        if ($comment === null || trim($comment) === '') {
            throw ValidationException::withMessages(['comment' => [__('budgeting_errors.approval.reason_required')]]);
        }

        return $this->transition($version, [BudgetVersion::Submitted, BudgetVersion::Approved], BudgetVersion::Draft, BudgetApproval::Reject, $userId, $comment, function (BudgetVersion $locked): void {
            if ($locked->status === BudgetVersion::Approved && $locked->isFormalBudget()) {
                throw ValidationException::withMessages(['status' => [__('budgeting_errors.approval.formal_immutable')]]);
            }

            $locked->submitted_by = null;
            $locked->submitted_at = null;
            $locked->approved_by = null;
            $locked->approved_at = null;
        });
    }

    public function lock(BudgetVersion $version, ?string $userId, ?string $comment): BudgetVersion
    {
        return $this->transition($version, [BudgetVersion::Approved], BudgetVersion::Locked, BudgetApproval::Lock, $userId, $comment, function (BudgetVersion $locked) use ($userId): void {
            $locked->locked_by = $userId;
            $locked->locked_at = now();
        });
    }

    /**
     * @param  list<string>  $from
     * @param  callable(BudgetVersion): void  $apply
     */
    private function transition(BudgetVersion $version, array $from, string $to, string $action, ?string $userId, ?string $comment, callable $apply): BudgetVersion
    {
        return DB::transaction(function () use ($version, $from, $to, $action, $userId, $comment, $apply): BudgetVersion {
            TransactionLock::acquire('budget_year:'.$version->tenant_id.':'.$version->fiscal_year);
            $locked = BudgetVersion::query()->findOrFail($version->id);

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => [__('budgeting_errors.approval.invalid_transition', ['status' => $locked->status, 'action' => $action])]]);
            }

            $apply($locked);
            $previous = $locked->status;
            $locked->status = $to;
            $locked->save();

            BudgetApproval::create([
                'tenant_id' => $locked->tenant_id,
                'version_id' => $locked->id,
                'action' => $action,
                'from_status' => $previous,
                'to_status' => $to,
                'user_id' => $userId,
                'comment' => $comment,
            ]);

            return $locked->refresh();
        });
    }
}
