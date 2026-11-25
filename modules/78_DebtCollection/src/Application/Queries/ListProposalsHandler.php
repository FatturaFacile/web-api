<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Queries;

use Illuminate\Support\Carbon;
use Modules\DebtCollection\Services\ProposalService;

final class ListProposalsHandler
{
    public function __construct(
        private ProposalService $proposals,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, groups: list<array<string, mixed>>}
     */
    public function handle(ListProposalsQuery $query): array
    {
        return $this->proposals->build($query->tenantId, $query->filters, Carbon::parse($query->asOf));
    }
}
