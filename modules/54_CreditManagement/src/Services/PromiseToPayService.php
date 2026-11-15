<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Models\CreditPromiseToPay;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Promises to pay. One promise is pending per case, for no more than what is outstanding and for today or later. A
 * promise is kept when the customer paid at least the promised amount, otherwise it is broken; either way the case
 * re-reads its receivables and goes back to dunning (or is resolved when nothing is left).
 */
final readonly class PromiseToPayService
{
    public function __construct(private CollectionCaseService $cases) {}

    /**
     * @param  array{promised_amount: string|int|float, promised_on: string, notes?: string|null}  $data
     */
    public function create(CreditCollectionCase $case, ?string $userId, array $data): CreditPromiseToPay
    {
        return DB::transaction(function () use ($case, $userId, $data): CreditPromiseToPay {
            $case = CreditCollectionCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($case->isClosed()) {
                throw ValidationException::withMessages(['case_id' => [__('credit_errors.case.closed')]]);
            }

            if ($case->promises()->where('status', CreditPromiseToPay::Pending)->exists()) {
                throw ValidationException::withMessages(['case_id' => [__('credit_errors.promise.pending_exists')]]);
            }

            if (Carbon::parse($data['promised_on'])->lt(Carbon::today())) {
                throw ValidationException::withMessages(['promised_on' => [__('credit_errors.promise.past_date')]]);
            }

            $amount = Decimal::round2((string) $data['promised_amount']);

            if (Decimal::compare($amount, (string) $case->outstanding_amount) > 0) {
                throw ValidationException::withMessages(['promised_amount' => [__('credit_errors.promise.amount_exceeds', ['outstanding' => $case->outstanding_amount])]]);
            }

            $promise = CreditPromiseToPay::create([
                'tenant_id' => $case->tenant_id,
                'case_id' => $case->id,
                'promised_amount' => $amount,
                'promised_on' => $data['promised_on'],
                'status' => CreditPromiseToPay::Pending,
                'recorded_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->cases->settle($case);

            return $promise->refresh();
        });
    }

    /**
     * Closes a pending promise as kept, broken or cancelled.
     *
     * @param  array{outcome: string, paid_amount?: string|int|float|null, resolved_on?: string|null}  $data
     */
    public function resolve(CreditPromiseToPay $promise, array $data): CreditPromiseToPay
    {
        return DB::transaction(function () use ($promise, $data): CreditPromiseToPay {
            $promise = CreditPromiseToPay::query()->whereKey($promise->id)->lockForUpdate()->firstOrFail();

            if ($promise->status !== CreditPromiseToPay::Pending) {
                throw ValidationException::withMessages(['status' => [__('credit_errors.promise.not_pending')]]);
            }

            $paid = null;

            if ($data['outcome'] === CreditPromiseToPay::Kept) {
                $paid = isset($data['paid_amount']) ? Decimal::round2((string) $data['paid_amount']) : null;

                if ($paid === null) {
                    throw ValidationException::withMessages(['paid_amount' => [__('credit_errors.promise.paid_required')]]);
                }

                if (Decimal::compare($paid, (string) $promise->promised_amount) < 0) {
                    throw ValidationException::withMessages(['paid_amount' => [__('credit_errors.promise.paid_below', ['promised' => $promise->promised_amount])]]);
                }
            } elseif (isset($data['paid_amount'])) {
                $paid = Decimal::round2((string) $data['paid_amount']);
            }

            $promise->update(['status' => $data['outcome'], 'paid_amount' => $paid, 'resolved_on' => $data['resolved_on'] ?? Carbon::today()->toDateString()]);
            $this->cases->refresh($promise->case()->firstOrFail());

            return $promise->refresh();
        });
    }

    /**
     * Marks as broken every pending promise whose day has passed.
     *
     * @return int number of promises marked
     */
    public function markBroken(string $tenantId, Carbon $today): int
    {
        $count = 0;

        $due = CreditPromiseToPay::query()->where('tenant_id', $tenantId)->where('status', CreditPromiseToPay::Pending)->whereDate('promised_on', '<', $today->toDateString())->get();

        foreach ($due as $promise) {
            $this->resolve($promise, ['outcome' => CreditPromiseToPay::Broken, 'resolved_on' => $today->toDateString()]);
            $count++;
        }

        return $count;
    }
}
