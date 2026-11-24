<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Handlers;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\MrpPlanning\Application\Queries\ListMrpProposalsQuery;
use Modules\MrpPlanning\Domain\Enums\MrpRunStatus;
use Modules\MrpPlanning\Domain\Exceptions\MrpNotFoundException;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;

/**
 * Proposals of a run filtered by type, status, exception code, item, work centre and need-by window, sortable on the
 * columns of the workbench. Without a run id the latest completed run is used.
 */
final class ListMrpProposalsHandler
{
    private const array SortColumns = ['need_by_date', 'release_order_by_date', 'suggested_order_qty', 'net_requirement_qty', 'gross_requirement_qty', 'proposal_type', 'status', 'low_level_code', 'created_at'];

    /**
     * @return LengthAwarePaginator<int, MrpActionProposal>
     */
    public function handle(ListMrpProposalsQuery $query): LengthAwarePaginator
    {
        $runId = $query->runId ?? MrpRunExecution::query()->where('tenant_id', $query->tenantId)->where('status', MrpRunStatus::Completed->value)->orderByDesc('completed_at')->value('id');
        if ($runId === null) {
            throw new MrpNotFoundException('There is no completed MRP run yet.');
        }

        $sort = in_array($query->sortBy, self::SortColumns, true) ? $query->sortBy : 'need_by_date';
        $direction = strtolower($query->sortDirection) === 'desc' ? 'desc' : 'asc';

        return MrpActionProposal::query()->with('item')
            ->where('tenant_id', $query->tenantId)->where('mrp_run_id', $runId)
            ->when($query->proposalType !== null, static fn ($builder) => $builder->where('proposal_type', $query->proposalType))
            ->when($query->status !== null, static fn ($builder) => $builder->where('status', $query->status))
            ->when($query->itemId !== null, static fn ($builder) => $builder->where('mfg_item_id', $query->itemId))
            ->when($query->workCenterId !== null, static fn ($builder) => $builder->where('work_center_id', $query->workCenterId))
            ->when($query->needByFrom !== null, static fn ($builder) => $builder->whereDate('need_by_date', '>=', $query->needByFrom?->toDateString()))
            ->when($query->needByTo !== null, static fn ($builder) => $builder->whereDate('need_by_date', '<=', $query->needByTo?->toDateString()))
            ->when($query->exceptionCode !== null, static fn ($builder) => $builder->whereRaw('CAST(exception_messages AS TEXT) LIKE ?', ['%"'.str_replace(['%', '_', '"'], '', (string) $query->exceptionCode).'"%']))
            ->orderBy($sort, $direction)->orderBy('id')
            ->paginate(perPage: max(1, min(200, $query->perPage)), page: max(1, $query->page));
    }
}
