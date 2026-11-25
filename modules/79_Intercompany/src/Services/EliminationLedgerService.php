<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\User;
use Modules\GeneralLedger\Models\ConsolidationAdjustment;
use Modules\GeneralLedger\Models\ConsolidationAdjustmentLine;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationService;

/**
 * The elimination and consolidation journal of a run, line by line: which rule generated it (receivable/payable,
 * revenue/cost, unrealised profit on stock, investment against equity, goodwill, dividends, manual), which entity and account it
 * touches and what the account held before and after the line, so that the controller can follow any consolidated figure
 * back to its adjustments. Lines are replayed in the order they were generated; the manual adjustments come last.
 */
final readonly class EliminationLedgerService
{
    public function __construct(
        private ConsolidationService $consolidation,
        private RunFigures $figures,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ledger(User $user, ConsolidationRun $run, ?string $type = null, ?string $entityTenantId = null): array
    {
        $this->consolidation->assertCanReadRun($user, $run);

        $running = [];

        foreach ($this->figures->accounts($run, false) as $code => $account) {
            $running[$code] = $account['aggregated_net'];
        }

        $entries = [];
        $totals = [];

        $adjustments = ConsolidationAdjustment::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->orderBy('created_at')->orderBy('id')->get();

        foreach ($adjustments as $adjustment) {
            $lines = ConsolidationAdjustmentLine::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->where('adjustment_id', $adjustment->id)->orderBy('line_number')->get();
            $entries[] = [
                'adjustment_id' => (string) $adjustment->id,
                'elimination_type' => $adjustment->adjustment_type->value,
                'description' => (string) $adjustment->description,
                'relation_id' => $adjustment->relation_id !== null ? (string) $adjustment->relation_id : null,
                'member_tenant_id' => $adjustment->member_tenant_id !== null ? (string) $adjustment->member_tenant_id : null,
                'reference' => null,
                'amount' => ConsolidationMath::norm((string) $adjustment->amount),
                'lines' => $this->replay($lines->map(static fn (ConsolidationAdjustmentLine $line): array => [
                    'line_number' => (int) $line->line_number,
                    'entity_tenant_id' => $line->entity_tenant_id !== null ? (string) $line->entity_tenant_id : null,
                    'account_code' => (string) $line->account_code,
                    'account_name' => (string) $line->account_name,
                    'account_type' => (string) $line->account_type,
                    'debit' => (string) $line->debit,
                    'credit' => (string) $line->credit,
                ])->all(), $running),
            ];
        }

        foreach ($this->figures->manualAdjustments($run) as $manual) {
            $entries[] = [
                'adjustment_id' => (string) $manual->id,
                'elimination_type' => 'manual',
                'description' => (string) $manual->reason,
                'relation_id' => null,
                'member_tenant_id' => null,
                'reference' => (string) $manual->reference,
                'amount' => ConsolidationMath::norm((string) $manual->amount),
                'lines' => $this->replay(array_map(static fn (array $line, int $index): array => ['line_number' => $index + 1, ...$line], $manual->lines, array_keys($manual->lines)), $running),
            ];
        }

        $entries = array_values(array_filter($entries, static function (array $entry) use ($type, $entityTenantId): bool {
            if ($type !== null && $entry['elimination_type'] !== $type) {
                return false;
            }

            if ($entityTenantId === null) {
                return true;
            }

            return $entry['member_tenant_id'] === $entityTenantId || array_filter($entry['lines'], static fn (array $line): bool => $line['entity_tenant_id'] === $entityTenantId) !== [];
        }));

        foreach ($entries as $entry) {
            $totals[$entry['elimination_type']] = ConsolidationMath::add($totals[$entry['elimination_type']] ?? ConsolidationMath::zero(), $entry['amount']);
        }

        return [
            'run_id' => (string) $run->id,
            'currency' => (string) $run->group_currency,
            'entries' => $entries,
            'totals_by_type' => $totals,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, string>  $running
     * @return list<array<string, mixed>>
     */
    private function replay(array $lines, array &$running): array
    {
        $result = [];

        foreach ($lines as $line) {
            $code = (string) $line['account_code'];
            $pre = $running[$code] ?? ConsolidationMath::zero();
            $post = ConsolidationMath::add($pre, ConsolidationMath::sub((string) $line['debit'], (string) $line['credit']));
            $running[$code] = $post;
            $result[] = [...$line, 'pre_elimination_amount' => $pre, 'post_elimination_amount' => $post];
        }

        return $result;
    }
}
