<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Domain\SpendRequest;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetOverride;
use Modules\Budgeting\Models\BudgetSetting;

/**
 * Override (deroga) requests: a requester asks to spend beyond the budget, the CFO or another authorised approver
 * approves (possibly for a lower amount and until a date) or rejects, and the spend control honours an approved
 * override once. Every step is kept in the audit trail. The requester can never approve their own request.
 */
final readonly class OverrideService
{
    public function __construct(private AvailabilityService $availability, private LedgerLookup $ledger, private AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(string $tenantId, ?string $userId, array $data): BudgetOverride
    {
        $scope = (string) ($data['scope'] ?? BudgetOverride::ScopeDocument);
        $date = isset($data['date']) ? Carbon::parse((string) $data['date']) : now();
        $this->assertDimensions($tenantId, $data);

        if ($scope === BudgetOverride::ScopeDocument && (empty($data['source_type']) || empty($data['source_id']))) {
            throw ValidationException::withMessages(['source_id' => [__('budgeting_errors.override.source_required')]]);
        }

        $requested = bcadd((string) $data['requested_amount'], '0', 2);
        $availability = $this->availability->forScope($tenantId, $date, $data['account_id'] ?? null, $data['cost_center_id'] ?? null, $data['project_id'] ?? null, $data['source_type'] ?? null, $data['source_id'] ?? null);
        $shortfall = bcsub(bcadd(bcadd($availability->actual, $availability->committed, 2), $requested, 2), $availability->budget, 2);
        $shortfall = bccomp($shortfall, '0', 2) < 0 ? '0.00' : $shortfall;

        $exists = $scope === BudgetOverride::ScopeDocument && BudgetOverride::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', $data['source_type'])
            ->where('source_id', $data['source_id'])
            ->whereIn('status', [BudgetOverride::Pending, BudgetOverride::Approved])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['source_id' => [__('budgeting_errors.override.already_open')]]);
        }

        return DB::transaction(function () use ($tenantId, $userId, $data, $scope, $availability, $requested, $shortfall): BudgetOverride {
            $override = BudgetOverride::create([
                'tenant_id' => $tenantId,
                'scope' => $scope,
                'source_type' => $scope === BudgetOverride::ScopeDocument ? $data['source_type'] : null,
                'source_id' => $scope === BudgetOverride::ScopeDocument ? $data['source_id'] : null,
                'source_number' => $data['source_number'] ?? null,
                'account_id' => $data['account_id'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'fiscal_year' => $availability->fiscalYear,
                'requested_amount' => $requested,
                'shortfall_amount' => $shortfall,
                'reason' => $data['reason'],
                'status' => BudgetOverride::Pending,
                'requested_by' => $userId,
                'valid_until' => $data['valid_until'] ?? null,
            ])->refresh();

            $this->audit->record($tenantId, BudgetAuditEvent::SubjectOverride, $override->id, 'request', $userId, null, BudgetOverride::Pending, $override->reason, ['requested' => $requested, 'shortfall' => $shortfall, 'scope' => $scope]);

            return $override;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function approve(BudgetOverride $override, ?string $approverId, array $data): BudgetOverride
    {
        return $this->decide($override, $approverId, BudgetOverride::Approved, $data['comment'] ?? null, function (BudgetOverride $locked) use ($data): void {
            $approved = isset($data['approved_amount']) ? bcadd((string) $data['approved_amount'], '0', 2) : (bccomp((string) $locked->shortfall_amount, '0', 2) > 0 ? (string) $locked->shortfall_amount : (string) $locked->requested_amount);
            $validUntil = isset($data['valid_until']) ? Carbon::parse((string) $data['valid_until']) : ($locked->valid_until ?? now()->addDays(BudgetSetting::forTenant($locked->tenant_id)->override_validity_days));

            if ($validUntil->lt(now()->startOfDay())) {
                throw ValidationException::withMessages(['valid_until' => [__('budgeting_errors.override.expiry_past')]]);
            }

            $locked->approved_amount = $approved;
            $locked->valid_until = $validUntil;
        });
    }

    public function reject(BudgetOverride $override, ?string $approverId, ?string $comment): BudgetOverride
    {
        if ($comment === null || trim($comment) === '') {
            throw ValidationException::withMessages(['comment' => [__('budgeting_errors.approval.reason_required')]]);
        }

        return $this->decide($override, $approverId, BudgetOverride::Rejected, $comment, static function (): void {});
    }

    public function cancel(BudgetOverride $override, ?string $userId, ?string $comment): BudgetOverride
    {
        return DB::transaction(function () use ($override, $userId, $comment): BudgetOverride {
            $locked = BudgetOverride::query()->whereKey($override->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [BudgetOverride::Pending, BudgetOverride::Approved], true)) {
                throw ValidationException::withMessages(['status' => [__('budgeting_errors.override.not_open')]]);
            }

            $from = $locked->status;
            $locked->update(['status' => BudgetOverride::Cancelled]);
            $this->audit->record($locked->tenant_id, BudgetAuditEvent::SubjectOverride, $locked->id, 'cancel', $userId, $from, BudgetOverride::Cancelled, $comment);

            return $locked->refresh();
        });
    }

    /**
     * The approved, unexpired override that covers a shortfall of a request: the override of the document first, then
     * an allowance of the account, cost centre or project that still has enough left.
     */
    public function coverageFor(SpendRequest $request, string $shortfall): ?BudgetOverride
    {
        $candidates = BudgetOverride::query()
            ->where('tenant_id', $request->tenantId)
            ->where('status', BudgetOverride::Approved)
            ->orderByDesc('scope')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (BudgetOverride $override): bool => ! $override->isExpired()
                && bccomp($override->remaining(), $shortfall, 2) >= 0
                && ($override->isDocument()
                    ? $override->source_type === $request->sourceType && $override->source_id === $request->sourceId
                    : ($override->account_id === null || $override->account_id === $request->accountId)
                        && ($override->cost_center_id === null || $override->cost_center_id === $request->costCenterId)
                        && ($override->project_id === null || $override->project_id === $request->projectId)));

        return $candidates->first();
    }

    /**
     * Uses an override: a document override is consumed whole, an allowance by the amount that went over the budget.
     */
    public function consume(BudgetOverride $override, string $amount, ?string $userId): void
    {
        $consumed = $override->isDocument() ? (string) $override->approved_amount : bcadd((string) $override->consumed_amount, $amount, 2);
        $exhausted = $override->isDocument() || bccomp($consumed, (string) $override->approved_amount, 2) >= 0;
        $override->update(['consumed_amount' => $consumed, 'status' => $exhausted ? BudgetOverride::Consumed : BudgetOverride::Approved]);
        $this->audit->record($override->tenant_id, BudgetAuditEvent::SubjectOverride, $override->id, 'consume', $userId, BudgetOverride::Approved, $override->status, null, ['amount' => $amount]);
    }

    /**
     * @param  callable(BudgetOverride): void  $apply
     */
    private function decide(BudgetOverride $override, ?string $approverId, string $to, ?string $comment, callable $apply): BudgetOverride
    {
        return DB::transaction(function () use ($override, $approverId, $to, $comment, $apply): BudgetOverride {
            $locked = BudgetOverride::query()->whereKey($override->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BudgetOverride::Pending) {
                throw ValidationException::withMessages(['status' => [__('budgeting_errors.override.not_pending')]]);
            }

            if ($approverId !== null && $locked->requested_by === $approverId) {
                throw ValidationException::withMessages(['status' => [__('budgeting_errors.override.four_eyes')]]);
            }

            $apply($locked);
            $locked->fill(['status' => $to, 'decided_by' => $approverId, 'decided_at' => now(), 'decision_comment' => $comment])->save();
            $this->audit->record($locked->tenant_id, BudgetAuditEvent::SubjectOverride, $locked->id, $to === BudgetOverride::Approved ? 'approve' : 'reject', $approverId, BudgetOverride::Pending, $to, $comment, ['approved_amount' => $locked->approved_amount, 'valid_until' => $locked->valid_until?->toDateString()]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertDimensions(string $tenantId, array $data): void
    {
        if (! empty($data['account_id']) && $this->ledger->accounts($tenantId, [(string) $data['account_id']]) === []) {
            throw ValidationException::withMessages(['account_id' => [__('budgeting_errors.line.account_missing')]]);
        }

        if (! empty($data['cost_center_id']) && $this->ledger->costCenters($tenantId, [(string) $data['cost_center_id']]) === []) {
            throw ValidationException::withMessages(['cost_center_id' => [__('budgeting_errors.line.cost_center_missing')]]);
        }

        if (! empty($data['project_id']) && $this->ledger->projects($tenantId, [(string) $data['project_id']]) === []) {
            throw ValidationException::withMessages(['project_id' => [__('budgeting_errors.line.project_missing')]]);
        }
    }
}
