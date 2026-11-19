<?php

declare(strict_types=1);

namespace App\Services\Sales\Cpq;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\QuoteApprovalRequest;
use App\Models\QuoteApprovalTier;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hierarchical approval of non-standard discounts.
 *
 * The tiers of a company are ordered by level. A quote breaches a tier when a line discount or the global discount is
 * above its limit, or the gross margin is below its minimum; it then needs the approval of the highest level breached
 * and of every level below it. Submitting moves the quote to pending approval, the last approval approves it, any
 * rejection rejects it, and every decision records who decided, when, why and which threshold was triggered.
 */
final readonly class QuoteApprovalEvaluator
{
    public function __construct(
        private MarginCalculator $calculator,
        private QuoteMarginSimulator $simulator,
        private DocumentEventRecorder $events,
    ) {}

    /**
     * Levels the quote breaches with the thresholds that triggered them; empty when the quote is within standard terms.
     *
     * @return array{required_level: int, breaches: list<array{level: int, role: string, triggers: list<array{type: string, limit: string, actual: string}>}>, figures: array<string, string|null>}
     */
    public function evaluate(Quote $quote): array
    {
        $quote->loadMissing('items');
        $tiers = $this->tiers((string) $quote->tenant_id);
        $lines = $this->simulator->lines($quote);
        $figures = $this->calculator->calculate($lines)['totals'];
        $maxLine = '0';

        foreach ($lines as $line) {
            $discount = (string) ($line['discount_percentage'] ?? '0');
            $maxLine = bccomp($discount, $maxLine, 2) > 0 ? $discount : $maxLine;
        }

        $list = (string) $figures['list_total'];
        $net = (string) $figures['net_total'];
        $effective = bccomp($list, '0', 2) > 0 ? bcmul(bcsub('1', bcdiv($net, $list, 8), 8), '100', 2) : '0.00';
        $margin = $figures['margin_percent'];
        $breaches = [];

        foreach ($tiers as $tier) {
            $triggers = [];

            if ($tier->max_line_discount_percent !== null && bccomp($maxLine, (string) $tier->max_line_discount_percent, 2) > 0) {
                $triggers[] = ['type' => 'max_line_discount_percent', 'limit' => (string) $tier->max_line_discount_percent, 'actual' => $maxLine];
            }

            if ($tier->max_global_discount_percent !== null && bccomp($effective, (string) $tier->max_global_discount_percent, 2) > 0) {
                $triggers[] = ['type' => 'max_global_discount_percent', 'limit' => (string) $tier->max_global_discount_percent, 'actual' => $effective];
            }

            if ($tier->min_margin_percent !== null && $margin !== null && bccomp($margin, (string) $tier->min_margin_percent, 2) < 0) {
                $triggers[] = ['type' => 'min_margin_percent', 'limit' => (string) $tier->min_margin_percent, 'actual' => $margin];
            }

            if ($triggers !== []) {
                $breaches[] = ['level' => $tier->level, 'role' => $tier->approver_role, 'triggers' => $triggers];
            }
        }

        return [
            'required_level' => $breaches === [] ? 0 : max(array_column($breaches, 'level')),
            'breaches' => $breaches,
            'figures' => $figures,
        ];
    }

    /**
     * Opens the approval requests the quote needs and moves it to pending approval; a quote within standard terms is
     * left as it is.
     *
     * @return Collection<int, QuoteApprovalRequest> empty when no approval is needed
     *
     * @throws ValidationException when the quote cannot wait for approval
     */
    public function submit(Quote $quote, ?string $userId): Collection
    {
        return DB::transaction(function () use ($quote, $userId): Collection {
            $locked = Quote::query()->with('items')->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $evaluation = $this->evaluate($locked);

            if ($evaluation['required_level'] === 0) {
                return new Collection;
            }

            if (! QuoteStatus::allows($locked->status, QuoteStatus::PendingApproval)) {
                throw ValidationException::withMessages(['status' => __('sales.cpq.not_submittable')]);
            }

            $locked->approvalRequests()->where('status', QuoteApprovalRequest::Pending)->delete();
            $roles = [];
            $triggers = [];

            foreach ($evaluation['breaches'] as $breach) {
                $triggers[$breach['level']] = $breach['triggers'];
            }

            foreach ($this->tiers((string) $locked->tenant_id) as $tier) {
                if ($tier->level <= $evaluation['required_level']) {
                    $roles[$tier->level] = $tier->approver_role;
                }
            }

            $requests = new Collection;

            foreach ($roles as $level => $role) {
                $requests->push($locked->approvalRequests()->create([
                    'tenant_id' => $locked->tenant_id,
                    'level' => $level,
                    'required_role' => $role,
                    'status' => QuoteApprovalRequest::Pending,
                    'triggers' => $triggers[$level] ?? [],
                    'requested_by' => $userId,
                ]));
            }

            $locked->forceFill(['status' => QuoteStatus::PendingApproval->value])->save();
            $this->events->record($locked, 'approval_requested', ['levels' => array_keys($roles), 'required_level' => $evaluation['required_level']]);

            return $requests;
        });
    }

    /**
     * Approves the lowest pending level the role can decide; when no level is left the quote becomes approved.
     *
     * @throws ValidationException
     */
    public function approve(Quote $quote, string $userId, string $role): QuoteApprovalRequest
    {
        return DB::transaction(function () use ($quote, $userId, $role): QuoteApprovalRequest {
            $request = $this->nextPending($quote, $role);
            $request->forceFill(['status' => QuoteApprovalRequest::Approved, 'approved_by' => $userId, 'decided_at' => now()])->save();
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $this->events->record($locked, 'approval_level_approved', ['level' => $request->level, 'approved_by' => $userId, 'triggers' => $request->triggers]);

            if (! $locked->approvalRequests()->where('status', QuoteApprovalRequest::Pending)->exists()) {
                $locked->forceFill(['status' => QuoteStatus::Approved->value, 'approved_by' => $userId, 'approved_at' => now()])->save();
                $this->events->record($locked, 'approved', ['approved_by' => $userId]);
            }

            return $request;
        });
    }

    /**
     * Rejects the quote at the lowest pending level the role can decide.
     *
     * @throws ValidationException
     */
    public function reject(Quote $quote, string $userId, string $role, string $reason): QuoteApprovalRequest
    {
        return DB::transaction(function () use ($quote, $userId, $role, $reason): QuoteApprovalRequest {
            $request = $this->nextPending($quote, $role);
            $request->forceFill(['status' => QuoteApprovalRequest::Rejected, 'rejected_by' => $userId, 'decided_at' => now(), 'rejection_reason' => $reason])->save();
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $locked->approvalRequests()->where('status', QuoteApprovalRequest::Pending)->update(['status' => QuoteApprovalRequest::Rejected, 'rejected_by' => $userId, 'decided_at' => now(), 'rejection_reason' => $reason]);
            $locked->forceFill(['status' => QuoteStatus::Rejected->value])->save();
            $this->events->record($locked, 'rejected', ['level' => $request->level, 'rejected_by' => $userId, 'reason' => $reason, 'triggers' => $request->triggers]);

            return $request;
        });
    }

    /**
     * Whether the quote may be sent to the customer, signed or accepted: it needs no approval, or every level it needs
     * has approved it and none is pending or rejected.
     */
    public function isCleared(Quote $quote): bool
    {
        $required = $this->evaluate($quote)['required_level'];

        if ($required === 0) {
            return true;
        }

        $requests = $quote->approvalRequests()->get();

        if ($requests->contains(static fn (QuoteApprovalRequest $request): bool => $request->status !== QuoteApprovalRequest::Approved)) {
            return false;
        }

        $approved = $requests->pluck('level')->all();

        return $this->tiers((string) $quote->tenant_id)
            ->filter(static fn (QuoteApprovalTier $tier): bool => $tier->level <= $required)
            ->every(static fn (QuoteApprovalTier $tier): bool => in_array($tier->level, $approved, true));
    }

    private function nextPending(Quote $quote, string $role): QuoteApprovalRequest
    {
        $pending = $quote->approvalRequests()
            ->where('status', QuoteApprovalRequest::Pending)
            ->orderBy('level')
            ->lockForUpdate()
            ->get();
        $next = $pending->first();

        if ($next === null) {
            throw ValidationException::withMessages(['quote' => __('sales.cpq.nothing_to_approve')]);
        }

        // Owners and administrators decide every level; the other roles only the level of their role.
        if (! in_array($role, ['owner', 'admin'], true) && $next->required_role !== $role) {
            throw ValidationException::withMessages(['quote' => __('sales.cpq.approver_role_mismatch')]);
        }

        return $next;
    }

    /**
     * @return Collection<int, QuoteApprovalTier>
     */
    private function tiers(string $tenantId): Collection
    {
        return QuoteApprovalTier::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('level')->get();
    }
}
