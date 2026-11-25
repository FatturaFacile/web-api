<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\GeneralLedger\Models\ConsolidationAdjustmentLine;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Models\ConsolidationRunBalance;
use Modules\GeneralLedger\Services\Consolidation\ConsolidatedStatementService;
use Modules\Intercompany\Models\ManualAdjustment;

/**
 * The consolidated accounts of a run as the statements of this module see them: the aggregated balances of the fully and
 * proportionally consolidated members, the adjustment lines the run generated and the active manual adjustments of the
 * controller, per account code. The run snapshot itself is never changed; manual adjustments are an overlay.
 */
final readonly class RunFigures
{
    public function __construct(private ConsolidatedStatementService $statements) {}

    /**
     * @return array<string, array{account_code: string, account_name: string, account_type: string, aggregated_net: string, adjustments_net: string, net: string}>
     */
    public function accounts(ConsolidationRun $run, bool $withManual = true): array
    {
        $balances = ConsolidationRunBalance::withoutGlobalScopes()
            ->where('run_id', $run->id)
            ->where('tenant_id', $run->tenant_id)
            ->whereIn('consolidation_method', [ConsolidationMethod::Full->value, ConsolidationMethod::Proportional->value])
            ->get()
            ->map(static fn (ConsolidationRunBalance $row): array => $row->only(['member_tenant_id', 'account_code', 'account_name', 'account_type', 'group_net']))
            ->all();

        $lines = $this->systemLines($run);

        if ($withManual) {
            foreach ($this->manualLines($run) as $line) {
                $lines[] = $line;
            }
        }

        $order = array_map(static fn (array $member): string => (string) $member['tenant_id'], $run->members_snapshot);

        return $this->statements->consolidatedAccounts($balances, $lines, $order);
    }

    /**
     * @return list<array{account_code: string, account_name: string, account_type: string, debit: string, credit: string}>
     */
    public function systemLines(ConsolidationRun $run): array
    {
        return ConsolidationAdjustmentLine::withoutGlobalScopes()
            ->where('run_id', $run->id)
            ->where('tenant_id', $run->tenant_id)
            ->get()
            ->map(static fn (ConsolidationAdjustmentLine $line): array => $line->only(['account_code', 'account_name', 'account_type', 'debit', 'credit']))
            ->all();
    }

    /**
     * @return list<array{account_code: string, account_name: string, account_type: string, debit: string, credit: string}>
     */
    public function manualLines(ConsolidationRun $run): array
    {
        $lines = [];

        foreach ($this->manualAdjustments($run) as $adjustment) {
            foreach ($adjustment->lines as $line) {
                $lines[] = [
                    'account_code' => (string) $line['account_code'],
                    'account_name' => (string) ($line['account_name'] ?? $line['account_code']),
                    'account_type' => (string) $line['account_type'],
                    'debit' => (string) $line['debit'],
                    'credit' => (string) $line['credit'],
                ];
            }
        }

        return $lines;
    }

    /**
     * @return Collection<int, ManualAdjustment>
     */
    public function manualAdjustments(ConsolidationRun $run): Collection
    {
        return ManualAdjustment::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->where('status', 'active')->orderBy('created_at')->get();
    }
}
