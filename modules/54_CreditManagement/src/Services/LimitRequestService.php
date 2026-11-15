<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\CreditManagement\Models\CreditLimitRequest;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Credit limit requests. A request carries the limit asked, the current limit and the automatic proposal of the latest
 * score. An approver other than the requester decides it; asking for more than the proposal needs a decision note; an
 * approved limit is stored where the sales credit check reads it. One request per customer is pending at a time.
 */
final readonly class LimitRequestService
{
    public function __construct(private ReceivablesLookupInterface $receivables, private CreditScoringService $scoring) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, ?string $userId, array $data): CreditLimitRequest
    {
        $customerId = (string) $data['customer_id'];

        if ($this->receivables->customerName($tenantId, $customerId) === null) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.customer.missing')]]);
        }

        return DB::transaction(function () use ($tenantId, $userId, $data, $customerId): CreditLimitRequest {
            if (CreditLimitRequest::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->where('status', CreditLimitRequest::Pending)->exists()) {
                throw ValidationException::withMessages(['customer_id' => [__('credit_errors.limit.pending_exists')]]);
            }

            $proposal = $this->proposalOrNull($tenantId, $customerId);
            $requested = $data['requested_limit'] ?? $proposal['proposed_limit'] ?? null;

            if ($requested === null) {
                throw ValidationException::withMessages(['requested_limit' => [__('credit_errors.limit.amount_missing')]]);
            }

            return CreditLimitRequest::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'current_limit' => $this->receivables->creditLimit($tenantId, $customerId),
                'requested_limit' => Decimal::round2((string) $requested),
                'proposed_limit' => $proposal['proposed_limit'] ?? null,
                'currency' => $data['currency'] ?? (string) (Tenant::query()->find($tenantId)?->default_currency ?? 'EUR'),
                'status' => CreditLimitRequest::Pending,
                'score_id' => $proposal['score_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'requested_by' => $userId,
                'valid_until' => $data['valid_until'] ?? null,
            ])->refresh();
        });
    }

    /**
     * @param  array{approved_limit?: string|int|float|null, decision_note?: string|null, valid_until?: string|null}  $data
     */
    public function approve(CreditLimitRequest $request, string $userId, array $data): CreditLimitRequest
    {
        return DB::transaction(function () use ($request, $userId, $data): CreditLimitRequest {
            $request = CreditLimitRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertDecidable($request, $userId);

            $approved = Decimal::round2((string) ($data['approved_limit'] ?? $request->requested_limit));
            $note = $data['decision_note'] ?? null;

            if ($request->proposed_limit !== null && Decimal::compare($approved, (string) $request->proposed_limit) > 0 && ($note === null || trim($note) === '')) {
                throw ValidationException::withMessages(['decision_note' => [__('credit_errors.limit.note_required', ['proposed' => $request->proposed_limit])]]);
            }

            $validUntil = $data['valid_until'] ?? $request->valid_until?->toDateString();

            if ($validUntil !== null && Carbon::parse($validUntil)->lt(Carbon::today())) {
                throw ValidationException::withMessages(['valid_until' => [__('credit_errors.limit.valid_until_past')]]);
            }

            $this->receivables->applyCreditLimit((string) $request->tenant_id, (string) $request->customer_id, $approved, $userId);
            $request->update(['status' => CreditLimitRequest::Approved, 'approved_limit' => $approved, 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => $note, 'valid_until' => $validUntil]);

            return $request->refresh();
        });
    }

    public function reject(CreditLimitRequest $request, string $userId, ?string $note): CreditLimitRequest
    {
        $this->assertDecidable($request, $userId);
        $request->update(['status' => CreditLimitRequest::Rejected, 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => $note]);

        return $request->refresh();
    }

    /**
     * The requester withdraws a pending request (anyone with write access may cancel; no approval is needed).
     */
    public function cancel(CreditLimitRequest $request): CreditLimitRequest
    {
        if ($request->status !== CreditLimitRequest::Pending) {
            throw ValidationException::withMessages(['status' => [__('credit_errors.limit.not_pending')]]);
        }

        $request->update(['status' => CreditLimitRequest::Cancelled]);

        return $request->refresh();
    }

    private function assertDecidable(CreditLimitRequest $request, string $userId): void
    {
        if ($request->status !== CreditLimitRequest::Pending) {
            throw ValidationException::withMessages(['status' => [__('credit_errors.limit.not_pending')]]);
        }

        if ($request->requested_by !== null && (string) $request->requested_by === $userId) {
            throw ValidationException::withMessages(['status' => [__('credit_errors.limit.same_user')]]);
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function proposalOrNull(string $tenantId, string $customerId): ?array
    {
        try {
            return $this->scoring->proposeLimit($tenantId, $customerId);
        } catch (ValidationException) {
            return null;
        }
    }
}
