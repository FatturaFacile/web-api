<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationService;
use Modules\Intercompany\Exceptions\IntercompanyConflictException;
use Modules\Intercompany\Exceptions\IntercompanyException;
use Modules\Intercompany\Models\ManualAdjustment;
use Modules\Intercompany\Support\GroupScope;

/**
 * Manual consolidation adjustments of the controller: a balanced set of lines with a mandatory reference and reason,
 * stored next to the run (the snapshot of the run is never edited). Only a run that is not locked takes them; removing
 * one voids it and keeps the row for the audit trail.
 */
final readonly class ManualAdjustmentService
{
    public function __construct(
        private GroupScope $scope,
        private ConsolidationService $consolidation,
        private RunWorkflowService $workflow,
    ) {}

    /**
     * @param  list<array{entity_tenant_id?: ?string, account_code: string, account_name?: ?string, account_type: string, debit?: string|float|int|null, credit?: string|float|int|null}>  $lines
     *
     * @throws IntercompanyException when the lines do not balance or an entity is not in the group
     */
    public function create(User $user, ConsolidationRun $run, string $reference, string $reason, array $lines): ManualAdjustment
    {
        $this->consolidation->assertCanReadRun($user, $run);
        $this->workflow->assertEditable($run);
        $group = $this->scope->groupOfRun($run);

        $normalized = [];
        $debit = $credit = ConsolidationMath::zero();

        foreach ($lines as $line) {
            $entity = isset($line['entity_tenant_id']) ? (string) $line['entity_tenant_id'] : null;

            if ($entity !== null) {
                $this->scope->assertEntities($group, [$entity]);
            }

            $d = ConsolidationMath::norm((string) ($line['debit'] ?? '0'));
            $c = ConsolidationMath::norm((string) ($line['credit'] ?? '0'));

            if (ConsolidationMath::isPositive($d) === ConsolidationMath::isPositive($c)) {
                throw new IntercompanyException('Every line has either a debit or a credit amount.', 'adjustment_line_invalid');
            }

            $normalized[] = [
                'entity_tenant_id' => $entity,
                'account_code' => (string) $line['account_code'],
                'account_name' => (string) ($line['account_name'] ?? $line['account_code']),
                'account_type' => (string) $line['account_type'],
                'debit' => $d,
                'credit' => $c,
            ];
            $debit = ConsolidationMath::add($debit, $d);
            $credit = ConsolidationMath::add($credit, $c);
        }

        if (ConsolidationMath::cmp($debit, $credit) !== 0) {
            throw new IntercompanyException('The adjustment does not balance: debits and credits must be equal.', 'adjustment_unbalanced');
        }

        return $this->scope->runAs((string) $run->tenant_id, fn (): ManualAdjustment => DB::transaction(fn (): ManualAdjustment => ManualAdjustment::withoutGlobalScopes()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->id,
            'reference' => $reference,
            'reason' => $reason,
            'status' => 'active',
            'amount' => $debit,
            'lines' => $normalized,
        ])));
    }

    public function void(User $user, ManualAdjustment $adjustment): ManualAdjustment
    {
        $run = ConsolidationRun::withoutGlobalScopes()->where('tenant_id', $adjustment->tenant_id)->findOrFail($adjustment->run_id);
        $this->consolidation->assertCanReadRun($user, $run);
        $this->workflow->assertEditable($run);

        if ($adjustment->status === 'void') {
            throw new IntercompanyConflictException('The adjustment is already void.', 'adjustment_void');
        }

        $adjustment->forceFill(['status' => 'void'])->save();

        return $adjustment->refresh();
    }
}
