<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Support\WeightedValue;

/**
 * Read model of the Kanban board: one column per stage of a pipeline with its deals and the raw / weighted totals.
 * Totals are computed over every deal that matches the filters, even when a column is truncated to `per_column`.
 */
final readonly class SalesBoardService
{
    public function __construct(private SalesPipelineService $pipelines) {}

    /**
     * @param  array{owner_user_id?: string|null, from?: string|null, to?: string|null, forecast_category?: string|null, search?: string|null, per_column?: int|null}  $filters
     * @return array{pipeline: Pipeline, columns: list<array{stage: OpportunityStage, opportunities: Collection<int, Opportunity>, totals: array{count: int, amount: string, weighted_amount: string}}>, totals: array{count: int, amount: string, weighted_amount: string}}
     */
    public function board(string $tenantId, ?string $pipelineId, array $filters = []): array
    {
        $default = $this->pipelines->ensureDefaultPipeline($tenantId);
        $pipeline = $pipelineId === null || $pipelineId === $default->id
            ? $default
            : Pipeline::where('tenant_id', $tenantId)->findOrFail($pipelineId);

        $stages = OpportunityStage::where('tenant_id', $tenantId)
            ->where('pipeline_id', $pipeline->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('code')
            ->get();

        $limit = max(1, min((int) ($filters['per_column'] ?? 100), 500));
        $columns = [];
        $grand = ['count' => 0, 'amount' => '0.00', 'weighted_amount' => '0.00'];

        foreach ($stages as $stage) {
            $totals = $this->totals($this->filtered($tenantId, $pipeline->id, $filters)->where('stage_id', $stage->id));
            $opportunities = $this->filtered($tenantId, $pipeline->id, $filters)
                ->where('stage_id', $stage->id)
                ->with(['account', 'stage'])
                ->orderBy('board_position')
                ->orderBy('created_at')
                ->limit($limit)
                ->get();

            $columns[] = ['stage' => $stage, 'opportunities' => $opportunities, 'totals' => $totals];
            $grand['count'] += $totals['count'];
            $grand['amount'] = bcadd($grand['amount'], $totals['amount'], 2);
            $grand['weighted_amount'] = bcadd($grand['weighted_amount'], $totals['weighted_amount'], 2);
        }

        return ['pipeline' => $pipeline, 'columns' => $columns, 'totals' => $grand];
    }

    /**
     * Totals of one stage column (used by the move endpoint so the client can reconcile its optimistic numbers).
     *
     * @return array{count: int, amount: string, weighted_amount: string}
     */
    public function stageTotals(string $tenantId, string $stageId): array
    {
        return $this->totals(Opportunity::where('tenant_id', $tenantId)->where('stage_id', $stageId));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Opportunity>
     */
    private function filtered(string $tenantId, string $pipelineId, array $filters): Builder
    {
        return Opportunity::query()
            ->where('tenant_id', $tenantId)
            ->where('pipeline_id', $pipelineId)
            ->when(! empty($filters['owner_user_id']), fn (Builder $q) => $q->where('owner_user_id', $filters['owner_user_id']))
            ->when(! empty($filters['from']), fn (Builder $q) => $q->whereDate('expected_close_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn (Builder $q) => $q->whereDate('expected_close_date', '<=', $filters['to']))
            ->when(! empty($filters['forecast_category']), fn (Builder $q) => $q->where('forecast_category', $filters['forecast_category']))
            ->when(! empty($filters['search']), function (Builder $q) use ($filters): void {
                $term = '%'.mb_strtolower((string) $filters['search']).'%';
                $q->where(function (Builder $inner) use ($term): void {
                    $inner->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(opportunity_number) LIKE ?', [$term]);
                });
            });
    }

    /**
     * @param  Builder<Opportunity>  $query
     * @return array{count: int, amount: string, weighted_amount: string}
     */
    private function totals(Builder $query): array
    {
        $amount = '0.00';
        $weighted = '0.00';
        $count = 0;

        foreach ($query->get(['amount', 'probability']) as $row) {
            $amount = bcadd($amount, WeightedValue::round2(WeightedValue::decimal((string) $row->amount)), 2);
            $weighted = bcadd($weighted, WeightedValue::compute((string) $row->amount, (string) $row->probability), 2);
            $count++;
        }

        return ['count' => $count, 'amount' => $amount, 'weighted_amount' => $weighted];
    }
}
