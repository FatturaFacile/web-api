<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Calculators;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;
use Modules\KpiDashboards\Infrastructure\Services\IntercompanyEliminator;
use Modules\KpiDashboards\Infrastructure\Services\KpiContext;
use Modules\KpiDashboards\Infrastructure\Services\KpiFx;
use Modules\KpiDashboards\Infrastructure\Services\LedgerAggregator;
use stdClass;

/**
 * Sales cockpit: weighted pipeline (Mod 035), win rate, gross margin and the top customers and products.
 */
final class SalesKpiCalculator implements KpiCalculator
{
    public function __construct(
        private readonly LedgerAggregator $ledger,
        private readonly IntercompanyEliminator $eliminator,
        private readonly KpiFx $fx,
    ) {}

    public function supports(): array
    {
        return [KpiCode::WeightedPipeline, KpiCode::WinRate, KpiCode::GrossMargin, KpiCode::TopCustomers, KpiCode::TopProducts];
    }

    public function headline(KpiCode $kpi, CockpitFilter $filter): ?float
    {
        return match ($kpi) {
            KpiCode::WeightedPipeline => round(array_sum(array_column($this->pipeline($filter), 'weighted')), 2),
            KpiCode::WinRate => $this->winRate($filter)['rate'],
            KpiCode::GrossMargin => $this->margin($filter)['margin'],
            KpiCode::TopCustomers => round($this->salesLines($filter)->sum('revenue'), 2),
            KpiCode::TopProducts => round($this->salesLines($filter)->sum('revenue'), 2),
            default => null,
        };
    }

    public function payload(KpiCode $kpi, KpiContext $context): array
    {
        $filter = $context->filter;

        return match ($kpi) {
            KpiCode::WeightedPipeline => ['unit' => 'currency', 'stages' => array_map(static fn (array $stage): array => [
                'stage_id' => $stage['stage_id'], 'stage' => $stage['stage'], 'probability' => $stage['probability'], 'count' => $stage['count'],
                'amount' => $context->money($stage['amount']), 'weighted' => $context->money($stage['weighted']),
            ], $this->pipeline($filter))],
            KpiCode::WinRate => ['unit' => 'percent', ...$this->winRate($filter)],
            KpiCode::GrossMargin => $this->marginPayload($context),
            KpiCode::TopCustomers => $this->topPayload($context, 'contact_id', 'customer'),
            KpiCode::TopProducts => $this->topPayload($context, 'product_id', 'product'),
            default => [],
        };
    }

    /**
     * Open opportunities by stage, weighted by the probability of each opportunity (the stage default when unset).
     *
     * @return list<array{stage_id: string|null, stage: string, probability: float, count: int, amount: float, weighted: float}>
     */
    private function pipeline(CockpitFilter $filter): array
    {
        $rows = DB::table('crm_opportunities')
            ->leftJoin('crm_opportunity_stages', 'crm_opportunities.stage_id', '=', 'crm_opportunity_stages.id')
            ->where('crm_opportunities.tenant_id', $filter->tenantId)->whereNull('crm_opportunities.deleted_at')
            ->whereNotIn('crm_opportunities.status', ['won', 'lost'])
            ->get(['crm_opportunities.stage_id', 'crm_opportunities.amount', 'crm_opportunities.probability', 'crm_opportunities.currency', 'crm_opportunity_stages.name', 'crm_opportunity_stages.sequence', 'crm_opportunity_stages.default_probability']);

        $stages = [];

        foreach ($rows as $row) {
            $probability = $row->probability !== null ? (float) $row->probability : (float) ($row->default_probability ?? 0);
            $amount = $this->fx->toAccounting($filter->tenantId, (string) $row->currency, (float) $row->amount, $filter->to);
            $key = (string) ($row->stage_id ?? 'none');
            $stages[$key] ??= ['sequence' => (int) ($row->sequence ?? 0), 'stage_id' => $row->stage_id !== null ? (string) $row->stage_id : null, 'stage' => (string) ($row->name ?? 'none'), 'probability' => (float) ($row->default_probability ?? 0), 'count' => 0, 'amount' => 0.0, 'weighted' => 0.0];
            $stages[$key]['count']++;
            $stages[$key]['amount'] += $amount;
            $stages[$key]['weighted'] += $amount * $probability / 100;
        }

        uasort($stages, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return array_values(array_map(static fn (array $stage): array => [
            'stage_id' => $stage['stage_id'], 'stage' => $stage['stage'], 'probability' => $stage['probability'], 'count' => $stage['count'],
            'amount' => round($stage['amount'], 2), 'weighted' => round($stage['weighted'], 2),
        ], $stages));
    }

    /**
     * @return array{rate: float|null, won: int, lost: int}
     */
    private function winRate(CockpitFilter $filter): array
    {
        $counts = DB::table('crm_opportunities')
            ->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')->whereIn('status', ['won', 'lost'])
            ->whereDate('actual_close_date', '>=', $filter->from->toDateString())->whereDate('actual_close_date', '<=', $filter->to->toDateString())
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $won = (int) ($counts['won'] ?? 0);
        $lost = (int) ($counts['lost'] ?? 0);

        return ['rate' => $won + $lost > 0 ? round($won / ($won + $lost) * 100, 2) : null, 'won' => $won, 'lost' => $lost];
    }

    /**
     * @return array{revenue: float, cogs: float, margin: float|null}
     */
    private function margin(CockpitFilter $filter): array
    {
        $eliminated = $this->eliminator->eliminations($filter);
        $revenue = $this->ledger->net($filter, ['revenue'], $filter->from, $filter->to, null, true) - $eliminated['revenue'];
        $cogs = $this->ledger->net($filter, ['cogs'], $filter->from, $filter->to) - $eliminated['cogs'];

        return ['revenue' => round($revenue, 2), 'cogs' => round($cogs, 2), 'margin' => $revenue > 0 ? round(($revenue - $cogs) / $revenue * 100, 2) : null];
    }

    /**
     * @return array<string, mixed>
     */
    private function marginPayload(KpiContext $context): array
    {
        $series = [];

        foreach ($this->ledger->monthlyIncome($context->filter, $context->filter->from, $context->filter->to) as $month => $amounts) {
            $series[] = ['period' => $month, 'margin_percent' => $amounts['revenue'] > 0 ? round(($amounts['revenue'] - $amounts['cogs']) / $amounts['revenue'] * 100, 2) : null];
        }

        $totals = $this->margin($context->filter);

        return ['unit' => 'percent', 'revenue' => $context->money($totals['revenue']), 'cogs' => $context->money($totals['cogs']), 'series' => $series];
    }

    /**
     * Invoice lines of the period with revenue and cost (the product cost price at the quantity sold), credit notes negative.
     *
     * @return Collection<int, covariant object>
     */
    private function salesLines(CockpitFilter $filter): Collection
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->leftJoin('products', 'invoice_items.product_id', '=', 'products.id')
            ->where('invoices.tenant_id', $filter->tenantId)->whereNull('invoices.deleted_at')->whereNull('invoice_items.deleted_at')
            ->where('invoices.invoice_type', '!=', 'proforma')
            ->whereDate('invoices.date', '>=', $filter->from->toDateString())->whereDate('invoices.date', '<=', $filter->to->toDateString())
            ->get(['invoices.contact_id', 'invoices.invoice_type', 'invoices.exchange_rate', 'invoice_items.product_id', 'invoice_items.line_total', 'invoice_items.quantity', 'products.cost_price'])
            ->map(static function (object $row): stdClass {
                $sign = $row->invoice_type === 'credit_note' ? -1.0 : 1.0;
                $rate = (float) $row->exchange_rate > 0 ? (float) $row->exchange_rate : 1.0;

                return (object) [
                    'contact_id' => (string) $row->contact_id,
                    'product_id' => $row->product_id !== null ? (string) $row->product_id : null,
                    'revenue' => $sign * (float) $row->line_total * $rate,
                    'cost' => $sign * (float) $row->quantity * (float) ($row->cost_price ?? 0) * $rate,
                ];
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function topPayload(KpiContext $context, string $dimension, string $label): array
    {
        $groups = $this->salesLines($context->filter)->filter(static fn (object $line): bool => $line->{$dimension} !== null)->groupBy($dimension);
        $names = $this->names($context->filter->tenantId, $dimension, $groups->keys()->all());
        $total = (float) $this->salesLines($context->filter)->sum('revenue');

        $rows = $groups->map(static function (Collection $lines, string $id) use ($names, $total, $context): array {
            $revenue = (float) $lines->sum('revenue');

            return [
                'id' => $id,
                'name' => $names[$id] ?? $id,
                'revenue' => $context->money($revenue),
                'margin_percent' => $revenue != 0.0 ? round(($revenue - (float) $lines->sum('cost')) / $revenue * 100, 2) : null,
                'share_percent' => $total > 0 ? round($revenue / $total * 100, 2) : null,
            ];
        })->sortByDesc('revenue')->take(10)->values()->all();

        return ['unit' => 'currency', 'dimension' => $label, 'rows' => $rows];
    }

    /**
     * @param  list<string|int>  $ids
     * @return array<string, string>
     */
    private function names(string $tenantId, string $dimension, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        if ($dimension === 'product_id') {
            return DB::table('products')->where('tenant_id', $tenantId)->whereIn('id', $ids)->pluck('name', 'id')->map(static fn (mixed $name): string => (string) $name)->all();
        }

        return DB::table('contacts')->where('tenant_id', $tenantId)->whereIn('id', $ids)
            ->get(['id', 'company_name', 'first_name', 'last_name'])
            ->mapWithKeys(static fn (object $contact): array => [(string) $contact->id => (string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name))])->all();
    }
}
