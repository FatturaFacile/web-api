<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Application\Queries;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalCoordinate;
use Modules\TimeTravel\Domain\ValueObjects\TimeTravelDiffComparison;
use Modules\TimeTravel\Services\EventStreamReader;
use Modules\TimeTravel\Services\ProjectorRegistry;
use Modules\TimeTravel\Support\Amount;
use Modules\TimeTravel\Support\Instant;

/**
 * The same valid-time cut-off seen at two moments of knowledge: the rows that changed with their algebraic delta, and the backdated
 * entries (valid_at <= cut-off, known_at_from < recorded_at <= known_at_to) that explain every change, with their audit trail.
 */
final readonly class CompareBiTemporalCutOffDiffHandler
{
    public function __construct(
        private ReconstructStateAtBiTemporalCoordinateHandler $reconstruct,
        private EventStreamReader $reader,
        private ProjectorRegistry $projectors,
    ) {}

    public function handle(CompareBiTemporalCutOffDiffQuery $query): TimeTravelDiffComparison
    {
        $projector = $this->projectors->for($query->domain->aggregateType());

        $before = $this->comparable($query, $query->knownAtFrom);
        $after = $this->comparable($query, $query->knownAtTo);

        $entries = [];
        $touched = [];
        $authors = [];

        foreach ($this->reader->recordedBetween($query->tenantId, $query->domain->aggregateType(), $query->validAt, $query->knownAtFrom, $query->knownAtTo) as $event) {
            if (! $projector->matches($query->domain, $event, $query->filters)) {
                continue;
            }

            foreach ($projector->affectedKeys($query->domain, $event, $query->filters) as $key) {
                $touched[$key] = true;
            }

            $actor = $event->actor_user_id;

            if ($actor !== null && ! array_key_exists($actor, $authors)) {
                $authors[$actor] = User::query()->whereKey($actor)->value('name');
            }

            $entries[] = [
                'event_id' => (string) $event->id,
                'event_type' => $event->event_type,
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => $event->aggregate_id,
                'valid_at' => Instant::iso(Instant::from((string) $event->valid_at)),
                'recorded_at' => Instant::iso(Instant::from((string) $event->recorded_at)),
                'actor_user_id' => $actor,
                'actor_name' => $actor !== null ? $authors[$actor] : null,
                'source_document' => $projector->sourceDocument($event),
                'amount_delta' => $projector->amountDelta($query->domain, $event, $query->filters),
                'cryptographic_chain_hash' => $event->cryptographic_chain_hash,
                'payload' => $event->payload,
            ];
        }

        $rows = [];
        $totalBefore = '0';
        $totalAfter = '0';

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $key = (string) $key;
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            $valueBefore = $b['value'] ?? Amount::money(0);
            $valueAfter = $a['value'] ?? Amount::money(0);
            $delta = Amount::money(Amount::sub($valueAfter, $valueBefore));
            $changed = ! Amount::isZero($delta);

            $rows[] = [
                'key' => $key,
                'label' => (string) (($a ?? $b)['label'] ?? $key),
                'status' => ! $changed ? 'unchanged' : (isset($touched[$key]) ? 'backdated' : 'delta'),
                'before' => $valueBefore,
                'after' => $valueAfter,
                'delta' => $delta,
            ];
            $totalBefore = Amount::add($totalBefore, $b['weight'] ?? 0);
            $totalAfter = Amount::add($totalAfter, $a['weight'] ?? 0);
        }

        // Changed rows first, then by label.
        usort($rows, static fn (array $x, array $y): int => [$x['status'] === 'unchanged', $x['label']] <=> [$y['status'] === 'unchanged', $y['label']]);

        return new TimeTravelDiffComparison(
            $query->domain,
            $query->validAt,
            $query->knownAtFrom,
            $query->knownAtTo,
            $rows,
            $entries,
            ['before' => Amount::money($totalBefore), 'after' => Amount::money($totalAfter), 'delta' => Amount::money(Amount::sub($totalAfter, $totalBefore))],
        );
    }

    /**
     * @return array<string, array{label: string, value: string, weight: string}>
     */
    private function comparable(CompareBiTemporalCutOffDiffQuery $query, CarbonImmutable $knownAt): array
    {
        $state = $this->reconstruct->handle(new ReconstructStateAtBiTemporalCoordinateQuery(
            $query->tenantId,
            $query->domain,
            new BiTemporalCoordinate($query->validAt, $knownAt),
            $query->filters,
        ));

        return $this->projectors->for($query->domain->aggregateType())->comparable($query->domain, $state['rows']);
    }
}
