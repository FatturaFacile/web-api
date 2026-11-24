<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\Supplier;
use Modules\SupplierPortal\Models\BankChangeApproval;
use Modules\SupplierPortal\Models\BankChangeRequest;
use Modules\Treasury\Services\IbanValidator;

/**
 * Bank detail changes of suppliers, the classic payment fraud vector, so nothing is applied on a single word:
 * 1. the IBAN must be well formed (ISO 13616), and only one request per supplier is open at a time;
 * 2. someone verifies the change by calling the supplier on a number already on file (callback);
 * 3. config('supplier_portal.bank_change_approvals') different internal users approve, none of them the requester.
 * Only then the IBAN of the supplier contact changes, in the same transaction as the last approval. One rejection ends
 * the request. Ledger payloads carry the last four characters of the IBAN only.
 */
final readonly class BankChangeService
{
    public function __construct(
        private IbanValidator $ibans,
        private SupplierPortalAccessService $access,
        private SupplierPortalLedger $ledger,
    ) {}

    /**
     * Opens a request, from the portal (no user) or entered by an internal user.
     *
     * @param  array{account_holder: string, iban: string, bic?: ?string, bank_name?: ?string, reason?: ?string}  $data
     *
     * @throws BusinessRuleException
     */
    public function request(Supplier $supplier, array $data, string $source, ?string $userId): BankChangeRequest
    {
        $check = $this->ibans->check($data['iban']);

        if (! $check['is_valid']) {
            throw new BusinessRuleException("The IBAN is not valid ({$check['reason']}).");
        }

        $contact = $this->access->contactOf($supplier);

        if ($this->ibans->normalize((string) $contact->iban) === $check['iban']) {
            throw new BusinessRuleException('The IBAN is the one already on file.');
        }

        return DB::transaction(function () use ($supplier, $data, $source, $userId, $check, $contact): BankChangeRequest {
            Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            if (BankChangeRequest::query()->where('supplier_id', $supplier->id)->where('status', BankChangeRequest::Pending)->exists()) {
                throw new BusinessRuleException('A bank change request of this supplier is already open.');
            }

            $request = BankChangeRequest::create([
                'tenant_id' => $supplier->tenant_id,
                'supplier_id' => $supplier->id,
                'source' => $source,
                'requested_by' => $userId,
                'account_holder' => $data['account_holder'],
                'iban' => $check['iban'],
                'bic' => isset($data['bic']) ? strtoupper($data['bic']) : null,
                'bank_name' => $data['bank_name'] ?? null,
                'previous_iban' => $contact->iban !== null ? $this->ibans->normalize((string) $contact->iban) : null,
                'reason' => $data['reason'] ?? null,
                'status' => BankChangeRequest::Pending,
            ]);

            $this->ledger->record('SUPPLIER_BANK_CHANGE_REQUESTED', $request, ['supplier_id' => $supplier->id, 'source' => $source, 'iban_tail' => $this->tail($request->iban)]);

            return $request;
        });
    }

    /**
     * Records that the change was confirmed by calling the supplier on a number already on file.
     *
     * @throws BusinessRuleException
     */
    public function verifyCallback(BankChangeRequest $request, string $note, string $userId): BankChangeRequest
    {
        return DB::transaction(function () use ($request, $note, $userId): BankChangeRequest {
            $locked = $this->lock($request);

            if ($locked->requested_by !== null && $locked->requested_by === $userId) {
                throw new BusinessRuleException('The requester cannot verify their own request.');
            }

            $locked->update(['callback_verified_at' => now(), 'callback_verified_by' => $userId, 'callback_note' => $note]);
            $this->ledger->record('SUPPLIER_BANK_CHANGE_CALLBACK_VERIFIED', $locked, ['supplier_id' => $locked->supplier_id]);

            return $locked;
        });
    }

    /**
     * Adds an approval; the IBAN is applied with the last one required.
     *
     * @throws BusinessRuleException
     */
    public function approve(BankChangeRequest $request, string $userId, ?string $comment): BankChangeRequest
    {
        return DB::transaction(function () use ($request, $userId, $comment): BankChangeRequest {
            $locked = $this->lock($request);

            if ($locked->callback_verified_at === null) {
                throw new BusinessRuleException('Verify the change by calling the supplier before approving it.');
            }

            if ($locked->requested_by !== null && $locked->requested_by === $userId) {
                throw new BusinessRuleException('The requester cannot approve their own request.');
            }

            if (BankChangeApproval::query()->where('request_id', $locked->id)->where('user_id', $userId)->exists()) {
                throw new BusinessRuleException('You already decided on this request.');
            }

            BankChangeApproval::create(['tenant_id' => $locked->tenant_id, 'request_id' => $locked->id, 'user_id' => $userId, 'decision' => BankChangeApproval::Approved, 'comment' => $comment, 'decided_at' => now()]);
            $this->ledger->record('SUPPLIER_BANK_CHANGE_APPROVED_STEP', $locked, ['supplier_id' => $locked->supplier_id]);

            $approvals = BankChangeApproval::query()->where('request_id', $locked->id)->where('decision', BankChangeApproval::Approved)->count();

            if ($approvals >= max(2, (int) config('supplier_portal.bank_change_approvals'))) {
                $this->apply($locked);
            }

            return $locked->refresh()->load('approvals');
        });
    }

    /**
     * One rejection ends the request.
     *
     * @throws BusinessRuleException
     */
    public function reject(BankChangeRequest $request, string $userId, string $reason): BankChangeRequest
    {
        return DB::transaction(function () use ($request, $userId, $reason): BankChangeRequest {
            $locked = $this->lock($request);

            if (BankChangeApproval::query()->where('request_id', $locked->id)->where('user_id', $userId)->exists()) {
                throw new BusinessRuleException('You already decided on this request.');
            }

            BankChangeApproval::create(['tenant_id' => $locked->tenant_id, 'request_id' => $locked->id, 'user_id' => $userId, 'decision' => BankChangeApproval::Rejected, 'comment' => $reason, 'decided_at' => now()]);
            $locked->update(['status' => BankChangeRequest::Rejected, 'decided_at' => now(), 'rejection_reason' => $reason]);
            $this->ledger->record('SUPPLIER_BANK_CHANGE_REJECTED', $locked, ['supplier_id' => $locked->supplier_id]);

            return $locked->refresh()->load('approvals');
        });
    }

    /**
     * The requester (or a manager) withdraws a request that is still open.
     *
     * @throws BusinessRuleException
     */
    public function cancel(BankChangeRequest $request): BankChangeRequest
    {
        return DB::transaction(function () use ($request): BankChangeRequest {
            $locked = $this->lock($request);
            $locked->update(['status' => BankChangeRequest::Cancelled, 'decided_at' => now()]);
            $this->ledger->record('SUPPLIER_BANK_CHANGE_CANCELLED', $locked, ['supplier_id' => $locked->supplier_id]);

            return $locked;
        });
    }

    private function apply(BankChangeRequest $request): void
    {
        $supplier = Supplier::query()->findOrFail($request->supplier_id);
        $contact = $this->access->contactOf($supplier);
        $contact->update(['iban' => $request->iban]);

        $metadata = (array) ($supplier->metadata ?? []);
        $metadata['bank'] = ['account_holder' => $request->account_holder, 'bic' => $request->bic, 'bank_name' => $request->bank_name, 'changed_at' => now()->toIso8601String()];
        $supplier->update(['metadata' => $metadata]);

        $request->update(['status' => BankChangeRequest::Applied, 'decided_at' => now(), 'applied_at' => now()]);
        $this->ledger->record('SUPPLIER_BANK_CHANGE_APPLIED', $request, ['supplier_id' => $request->supplier_id, 'iban_tail' => $this->tail($request->iban)]);
    }

    private function lock(BankChangeRequest $request): BankChangeRequest
    {
        $locked = BankChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== BankChangeRequest::Pending) {
            throw new BusinessRuleException("A request in status {$locked->status} can no longer change.");
        }

        return $locked;
    }

    private function tail(string $iban): string
    {
        return substr($iban, -4);
    }
}
