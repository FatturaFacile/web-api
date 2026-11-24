<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\AgentCommissions\Models\CommissionEntry;
use Modules\Payroll\Calculation\Dec;

final class ListLedgerHandler
{
    /**
     * @return array{page: LengthAwarePaginator, totals: array<string, string>}
     */
    public function handle(ListLedgerQuery $query): array
    {
        $builder = CommissionEntry::query()
            ->when($query->agentProfileId !== null, static fn (Builder $q) => $q->where('agent_profile_id', $query->agentProfileId))
            ->when($query->status !== null, static fn (Builder $q) => $q->where('status', $query->status))
            ->when($query->accrualBasis !== null, static fn (Builder $q) => $q->where('accrual_basis', $query->accrualBasis))
            ->when($query->from !== null, static fn (Builder $q) => $q->whereDate('accrued_on', '>=', $query->from))
            ->when($query->to !== null, static fn (Builder $q) => $q->whereDate('accrued_on', '<=', $query->to))
            ->when($query->search !== null && $query->search !== '', static fn (Builder $q) => $q->whereHas('invoice', static fn (Builder $invoice) => $invoice->where('invoice_number', 'like', '%'.$query->search.'%')));

        $all = (clone $builder)->get(['commission_amount', 'credited_amount', 'eligible_amount', 'settled_amount', 'accrual_basis', 'status']);
        $effective = static fn (CommissionEntry $entry): string => Dec::sub((string) $entry->commission_amount, (string) $entry->credited_amount);
        $blocked = $all->where('accrual_basis', 'collected')->whereIn('status', ['pending_collection', 'awaiting_reconciliation', 'partially_eligible']);
        $totals = [
            'commission' => Dec::round(Dec::sum($all->map($effective))),
            'eligible' => Dec::round(Dec::sum($all->pluck('eligible_amount'))),
            'settled' => Dec::round(Dec::sum($all->pluck('settled_amount'))),
            'blocked' => Dec::round(Dec::sum($blocked->map(static fn (CommissionEntry $entry): string => Dec::max('0', Dec::sub($effective($entry), (string) $entry->eligible_amount))))),
        ];

        return [
            'page' => $builder->with(['agent.representative:id,name', 'invoice:id,invoice_number,date,contact_id'])->orderByDesc('accrued_on')->orderBy('id')->paginate($query->perPage),
            'totals' => $totals,
        ];
    }
}
