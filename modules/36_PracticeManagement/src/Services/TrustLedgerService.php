<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\LegalCase;
use App\Services\Tenancy\TenantContext;
use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\PracticeManagement\Models\PracticeTrustTransaction;

/**
 * Client funds (trust) ledger of a firm. Money held for a client is tracked per client and, when a row names a matter,
 * per matter. Deposits add to the balance; withdrawals and fee transfers take from it and are refused when the balance
 * of their scope (client or matter) would go below zero. The ledger is append-only: a mistake is corrected with a
 * reversal row. Amounts are decimal strings computed with bcmath; each client is serialised with a transaction lock.
 */
final class TrustLedgerService
{
    /**
     * @param  array{type: string, client_id: string, legal_case_id?: ?string, occurred_on: string, amount: string, reference?: ?string, description?: ?string}  $data
     */
    public function record(array $data, ?string $userId): PracticeTrustTransaction
    {
        $caseId = $data['legal_case_id'] ?? null;

        if ($caseId !== null && (string) LegalCase::query()->findOrFail($caseId)->client_id !== (string) $data['client_id']) {
            throw new BusinessRuleException('The matter belongs to another client.');
        }

        $signed = $data['type'] === 'deposit' ? Decimal::normalize($data['amount']) : Decimal::neg($data['amount']);

        return DB::transaction(fn (): PracticeTrustTransaction => $this->append([
            'type' => $data['type'],
            'client_id' => $data['client_id'],
            'legal_case_id' => $caseId,
            'occurred_on' => $data['occurred_on'],
            'amount' => Decimal::round2($data['amount']),
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'reverses_id' => null,
            'recorded_by' => $userId,
        ], $signed));
    }

    /**
     * Posts the opposite row of a ledger row. A deposit can be reversed only while the balance still covers it.
     */
    public function reverse(PracticeTrustTransaction $transaction, ?string $description, ?string $userId): PracticeTrustTransaction
    {
        return DB::transaction(function () use ($transaction, $description, $userId): PracticeTrustTransaction {
            TransactionLock::acquire($this->lockName((string) $transaction->client_id));

            if ($transaction->type === 'reversal') {
                throw new BusinessRuleException('A reversal cannot be reversed.');
            }

            if (PracticeTrustTransaction::query()->where('reverses_id', $transaction->id)->exists()) {
                throw new BusinessRuleException('The transaction is already reversed.');
            }

            return $this->append([
                'type' => 'reversal',
                'client_id' => $transaction->client_id,
                'legal_case_id' => $transaction->legal_case_id,
                'occurred_on' => now()->toDateString(),
                'amount' => $transaction->amount,
                'reference' => $transaction->reference,
                'description' => $description,
                'reverses_id' => $transaction->id,
                'recorded_by' => $userId,
            ], Decimal::neg($transaction->signed_amount));
        });
    }

    /**
     * Balance held for a client: of one matter, or of the funds that name no matter when the matter is null.
     */
    public function balance(string $clientId, ?string $caseId): string
    {
        $sum = PracticeTrustTransaction::query()
            ->where('client_id', $clientId)
            ->when($caseId === null, fn ($query) => $query->whereNull('legal_case_id'), fn ($query) => $query->where('legal_case_id', $caseId))
            ->sum('signed_amount');

        return Decimal::round2((string) $sum);
    }

    /**
     * Balance per client and matter, with the client total.
     *
     * @return list<array{client_id: string, client_name: string, total: string, matters: list<array{legal_case_id: ?string, balance: string}>}>
     */
    public function balances(): array
    {
        $rows = PracticeTrustTransaction::query()
            ->selectRaw('client_id, legal_case_id, SUM(signed_amount) as balance')
            ->groupBy('client_id', 'legal_case_id')
            ->get();

        $clients = [];

        foreach ($rows as $row) {
            $clients[(string) $row->client_id][] = ['legal_case_id' => $row->legal_case_id, 'balance' => Decimal::round2((string) $row->getAttribute('balance'))];
        }

        $names = Contact::query()->whereIn('id', array_keys($clients))->get()->keyBy('id');
        $result = [];

        foreach ($clients as $clientId => $matters) {
            $contact = $names->get($clientId);
            $result[] = [
                'client_id' => (string) $clientId,
                'client_name' => $contact === null ? '' : (string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name)),
                'total' => Decimal::round2(array_reduce($matters, fn (string $carry, array $matter): string => Decimal::add($carry, $matter['balance']), '0')),
                'matters' => $matters,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function append(array $attributes, string $signed): PracticeTrustTransaction
    {
        TransactionLock::acquire($this->lockName((string) $attributes['client_id']));

        $scopeBalance = $this->balance((string) $attributes['client_id'], $attributes['legal_case_id']);
        $after = Decimal::add($scopeBalance, $signed);

        if (Decimal::isNegative($after)) {
            throw new BusinessRuleException(sprintf('The client funds balance is only %s: the operation would take it below zero.', $scopeBalance));
        }

        $transaction = new PracticeTrustTransaction;
        $transaction->fill([...$attributes, 'signed_amount' => Decimal::round2($signed), 'balance_after' => $after]);
        $transaction->save();

        return $transaction;
    }

    private function lockName(string $clientId): string
    {
        return 'practice-trust:'.app(TenantContext::class)->getTenantId().':'.$clientId;
    }
}
