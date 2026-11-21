<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\AnalyticsDss\Application\Queries\GetDssDrillDownQuery;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReader;

/**
 * Paginated analytical records behind a KPI, bridge bar or matrix row, with the deep links to the source ERP entities and to
 * the report builder (Issue #215) and KPI dashboards (Mod 090) that continue the analysis.
 */
final class GetDssDrillDownHandler
{
    public const array Metrics = ['revenue', 'cogs', 'opex', 'depreciation', 'cash', 'receivable', 'payable', 'inventory'];

    public const array EntityTypes = ['customer', 'cost_center', 'legal_entity'];

    /**
     * @return array<string, mixed>
     */
    public function handle(GetDssDrillDownQuery $query): array
    {
        $filter = $query->filter;
        $currency = strtolower($filter->currency);
        $perPage = min(max($query->perPage, 1), (int) config('analytics_dss.drilldown_max_page_size', 200));
        $page = max($query->page, 1);

        $rows = DB::table('fact_financial_transactions as f')
            ->where('f.tenant_id', $filter->tenantId)->whereNull('f.deleted_at')
            ->where('f.category', $query->metric)
            ->whereBetween('f.date_key', [MartReader::dateKey($filter->from), MartReader::dateKey($filter->to)])
            ->when($filter->legalEntityIds !== [], fn ($q) => $q->whereIn('f.legal_entity_id', $filter->legalEntityIds))
            ->when($filter->costCenterIds !== [], fn ($q) => $q->whereIn('f.cost_center_id', $filter->costCenterIds))
            ->when($query->entityType !== null && $query->entityId !== null, function ($q) use ($query): void {
                $column = ['customer' => 'counterparty_id', 'cost_center' => 'cost_center_id', 'legal_entity' => 'legal_entity_id'][(string) $query->entityType] ?? null;

                if ($column !== null) {
                    $q->where("f.{$column}", $query->entityId);
                }
            });

        $total = (clone $rows)->count();
        $sum = (float) (clone $rows)->sum("f.amount_{$currency}");
        $sign = in_array($query->metric, ['revenue', 'payable'], true) ? -1 : 1;

        $items = $rows->leftJoin('dim_counterparty as c', function ($join): void {
            $join->on('c.contact_id', '=', 'f.counterparty_id')->on('c.tenant_id', '=', 'f.tenant_id');
        })
            ->orderByDesc('f.posting_date')->orderBy('f.id')
            ->forPage($page, $perPage)
            ->get(['f.id', 'f.posting_date', 'f.account_code', 'f.category', 'f.journal_entry_id', 'f.legal_entity_id', 'f.cost_center_id', 'f.counterparty_id', 'c.name as counterparty_name', 'f.transaction_currency', 'f.amount_transaction', "f.amount_{$currency} as amount_reporting"])
            ->map(static fn (object $r): array => [
                'id' => (string) $r->id,
                'posting_date' => substr((string) $r->posting_date, 0, 10),
                'account_code' => $r->account_code,
                'category' => $r->category,
                'counterparty' => $r->counterparty_id !== null ? ['id' => (string) $r->counterparty_id, 'name' => $r->counterparty_name] : null,
                'cost_center_id' => $r->cost_center_id,
                'legal_entity_id' => $r->legal_entity_id,
                'transaction_currency' => $r->transaction_currency,
                'amount_transaction' => round((float) $r->amount_transaction * $sign, 4),
                'amount_reporting' => $r->amount_reporting !== null ? round((float) $r->amount_reporting * $sign, 4) : null,
                'source' => ['type' => 'journal_entry', 'id' => (string) $r->journal_entry_id],
                'links' => [
                    'source' => "/api/v1/general-ledger/journal-entries/{$r->journal_entry_id}",
                    'report_builder' => '/api/v1/business-intelligence/explorer/run',
                ],
            ])->all();

        return [
            'metric' => $query->metric,
            'currency' => $filter->currency,
            'data' => $items,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max((int) ceil($total / $perPage), 1)],
            'total_amount' => round($sum * $sign, 4),
            'links' => ['kpi_dashboards' => '/api/v1/business-intelligence/dashboards', 'report_builder' => '/api/v1/business-intelligence/explorer/run'],
        ];
    }
}
