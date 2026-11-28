<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services\Projections;

use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Models\BiTemporalEvent;

/**
 * Folds the events of one stream into a projection. The fold is commutative (sums and last-writer-by-valid-time), so a checkpoint plus the
 * delta of the events recorded or effective after it gives exactly the state of the full stream, whatever the order of arrival.
 */
interface StateProjector
{
    public function aggregateType(): AggregateType;

    /**
     * The domain whose state is stored in the checkpoints of the stream.
     */
    public function checkpointDomain(): QueryDomain;

    /**
     * Whether a checkpoint can seed this domain (an account ledger lists entries, which a checkpoint does not keep).
     */
    public function usesCheckpoint(QueryDomain $domain): bool;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function initialState(QueryDomain $domain, array $filters): array;

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function apply(array $state, BiTemporalEvent $event): array;

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function render(QueryDomain $domain, array $state, array $filters, CarbonImmutable $validAt, string $tenantId): array;

    /**
     * The rows of a rendering as comparable values: key => label, signed value and the weight that enters the total.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{label: string, value: string, weight: string}>
     */
    public function comparable(QueryDomain $domain, array $rows): array;

    /**
     * Whether the event concerns the filtered view (account, partner type, warehouse, item...).
     *
     * @param  array<string, mixed>  $filters
     */
    public function matches(QueryDomain $domain, BiTemporalEvent $event, array $filters): bool;

    /**
     * The keys of the comparable rows the event changes.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public function affectedKeys(QueryDomain $domain, BiTemporalEvent $event, array $filters): array;

    /**
     * The algebraic effect of the event on the filtered view.
     *
     * @param  array<string, mixed>  $filters
     */
    public function amountDelta(QueryDomain $domain, BiTemporalEvent $event, array $filters): string;

    public function sourceDocument(BiTemporalEvent $event): ?string;
}
