<?php

declare(strict_types=1);

namespace App\Services\RecurringContracts;

use App\Models\RecurringContracts\RecurringContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and updates a recurring contract together with its lines and indexation clause in one transaction.
 */
class RecurringContractWriter
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated, string $tenantId): RecurringContract
    {
        return DB::transaction(function () use ($validated, $tenantId): RecurringContract {
            $contractNumber = $validated['contract_number'] ?? ('CTR-'.strtoupper(Str::random(8)));
            $nextBillingDate = $validated['next_billing_date'] ?? $validated['start_date'];

            $contractData = collect($validated)->except(['lines', 'indexation_clause'])->toArray();
            $contractData['contract_number'] = $contractNumber;
            $contractData['next_billing_date'] = $nextBillingDate;
            $contractData['tenant_id'] = $tenantId;
            $contractData['status'] = $contractData['status'] ?? 'draft';

            $contract = RecurringContract::create($contractData);

            if (! empty($validated['lines'])) {
                foreach ($validated['lines'] as $index => $line) {
                    $line['sort_order'] = $line['sort_order'] ?? $index;
                    $contract->lines()->create($line);
                }
            }

            if (! empty($validated['indexation_clause'])) {
                $contract->indexationClauses()->create($validated['indexation_clause']);
            }

            return $contract->load(['customer', 'lines', 'indexationClause']);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(RecurringContract $contract, array $validated): void
    {
        DB::transaction(function () use ($contract, $validated): void {
            $contractData = collect($validated)->except(['lines', 'indexation_clause'])->toArray();
            $contract->update($contractData);

            if (isset($validated['lines'])) {
                $contract->lines()->delete();
                foreach ($validated['lines'] as $index => $line) {
                    unset($line['id']);
                    $line['sort_order'] = $line['sort_order'] ?? $index;
                    $contract->lines()->create($line);
                }
            }

            if (isset($validated['indexation_clause'])) {
                $clauseData = $validated['indexation_clause'];
                if ($contract->indexationClause) {
                    $contract->indexationClause->update($clauseData);
                } else {
                    $contract->indexationClauses()->create($clauseData);
                }
            }
        });
    }
}
