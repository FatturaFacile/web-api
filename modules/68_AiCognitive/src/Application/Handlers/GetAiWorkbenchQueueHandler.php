<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\AiCognitive\Application\Queries\GetAiWorkbenchQueueQuery;
use Modules\AiCognitive\Domain\Enums\SuggestionStatus;
use Modules\AiCognitive\Infrastructure\Models\AiSuggestion;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;

/**
 * The Cognitive Review Workbench queue: the open items (pending and failed) with the most urgent first, and the counts of
 * the open items by kind for the filter chips.
 */
final readonly class GetAiWorkbenchQueueHandler
{
    public function __construct(private AiTenantRunner $runner) {}

    /**
     * @return array{items: LengthAwarePaginator<int, AiSuggestion>, open_by_kind: array<string, int>}
     */
    public function handle(GetAiWorkbenchQueueQuery $query): array
    {
        return $this->runner->run($query->tenantId, function () use ($query): array {
            $open = array_map(static fn (SuggestionStatus $status): string => $status->value, SuggestionStatus::open());

            $items = AiSuggestion::query()
                ->where('tenant_id', $query->tenantId)
                ->when($query->suggestionId !== null, static fn ($builder) => $builder->where('id', $query->suggestionId))
                ->when($query->status !== null, static fn ($builder) => $builder->where('status', $query->status), static fn ($builder) => $query->suggestionId !== null ? $builder : $builder->whereIn('status', $open))
                ->when($query->kind !== null, static fn ($builder) => $builder->where('kind', $query->kind))
                ->when($query->priority !== null, static fn ($builder) => $builder->where('priority', $query->priority))
                ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
                ->orderByDesc('created_at')
                ->paginate(max(1, min($query->perPage, 100)));

            $counts = AiSuggestion::query()->where('tenant_id', $query->tenantId)->whereIn('status', $open)
                ->selectRaw('kind, COUNT(*) as total')->groupBy('kind')->pluck('total', 'kind')->map(static fn ($total): int => (int) $total)->all();

            return ['items' => $items, 'open_by_kind' => $counts];
        });
    }
}
