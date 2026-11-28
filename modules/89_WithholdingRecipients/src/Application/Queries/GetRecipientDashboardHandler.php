<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Queries;

use Modules\WithholdingRecipients\Services\RecipientInsightService;

final class GetRecipientDashboardHandler
{
    public function __construct(private readonly RecipientInsightService $insight) {}

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function handle(GetRecipientDashboardQuery $query): array
    {
        return $this->insight->dashboard($query->filters);
    }
}
