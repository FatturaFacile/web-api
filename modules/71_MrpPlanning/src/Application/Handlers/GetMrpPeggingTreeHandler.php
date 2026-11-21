<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Handlers;

use Modules\MrpPlanning\Application\Queries\GetMrpPeggingTreeQuery;
use Modules\MrpPlanning\Domain\Exceptions\MrpNotFoundException;
use Modules\MrpPlanning\Domain\Services\PeggingTreeBuilder;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;

/**
 * The pegging graph of a proposal over the proposals of its run.
 */
final class GetMrpPeggingTreeHandler
{
    public function __construct(private readonly PeggingTreeBuilder $builder) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetMrpPeggingTreeQuery $query): array
    {
        $target = MrpActionProposal::query()->where('tenant_id', $query->tenantId)->whereKey($query->proposalId)->first();
        if (! $target instanceof MrpActionProposal) {
            throw new MrpNotFoundException('Proposal not found.');
        }

        $nodes = [];
        foreach (MrpActionProposal::query()->with('item')->where('tenant_id', $query->tenantId)->where('mrp_run_id', $target->mrp_run_id)->get() as $proposal) {
            $nodes[(string) $proposal->id] = [
                'id' => (string) $proposal->id,
                'item_id' => (string) $proposal->mfg_item_id,
                'item_code' => $proposal->item?->item_code,
                'item_name' => $proposal->item?->name,
                'proposal_type' => $proposal->proposal_type->value,
                'status' => $proposal->status->value,
                'quantity' => (string) $proposal->suggested_order_qty,
                'need_by_date' => $proposal->need_by_date->toDateString(),
                'pegging' => $proposal->pegged_demand_source_ids ?? [],
            ];
        }

        /** @var array<string, mixed> $tree */
        $tree = $this->builder->build((string) $target->id, $nodes);

        return $tree;
    }
}
