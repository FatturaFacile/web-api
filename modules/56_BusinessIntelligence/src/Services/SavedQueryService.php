<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use App\Models\User;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\BusinessIntelligence\Models\BiSavedQuery;

/**
 * Saved self-service queries and pivots. A query is stored as the validated specification of the semantic layer (never
 * SQL) plus the calculated measures it adds and the pivot layout; it is validated again every time it runs, because the
 * user who runs it may not read the dataset any more.
 */
final readonly class SavedQueryService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private ReportEngine $engine,
        private BiQueryService $queries,
        private PivotService $pivots,
        private TenantClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(string $tenantId, User $user, array $data): BiSavedQuery
    {
        $fields = $this->fields($user, $data);

        return BiSavedQuery::create([...$fields, 'tenant_id' => $tenantId, 'owner_id' => $user->id, 'visibility' => $data['visibility'] ?? BiSavedQuery::Private]);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(BiSavedQuery $query, User $user, array $data): BiSavedQuery
    {
        $merged = [...$query->only(['name', 'description', 'dataset', 'spec', 'calculated_measures', 'pivot', 'snapshot_frequency']), ...$data];
        $fields = $this->fields($user, $merged, $query);

        if (isset($data['visibility'])) {
            $fields['visibility'] = $data['visibility'];
        }

        $query->update($fields);

        return $query->refresh();
    }

    /**
     * Runs the query for a user and builds its pivot when it has one.
     *
     * @param  array<string, mixed>|null  $pivotOverride  layout to use instead of the saved one
     * @return array{result: array<string, mixed>, pivot: array<string, mixed>|null}
     *
     * @throws ValidationException
     */
    public function run(BiSavedQuery $query, User $user, ?array $pivotOverride = null): array
    {
        return $this->execute($query->dataset, $query->spec, $query->calculated_measures ?? [], $pivotOverride ?? $query->pivot, $user);
    }

    /**
     * Runs a query that is not saved yet (the explorer): same validation, nothing stored.
     *
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $calculated
     * @param  array<string, mixed>|null  $pivot
     * @return array{result: array<string, mixed>, pivot: array<string, mixed>|null}
     *
     * @throws ValidationException
     */
    public function runAdHoc(string $dataset, array $spec, array $calculated, ?array $pivot, User $user): array
    {
        return $this->execute($dataset, $spec, $calculated, $pivot, $user);
    }

    /**
     * The next day a snapshot is due after the given day.
     */
    public function nextSnapshotAfter(CarbonImmutable $day, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $day->addWeek(),
            'monthly' => $day->addMonthNoOverflow(),
            default => $day->addDay(),
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $calculated
     * @param  array<string, mixed>|null  $pivot
     * @return array{result: array<string, mixed>, pivot: array<string, mixed>|null}
     */
    private function execute(string $dataset, array $spec, array $calculated, ?array $pivot, User $user): array
    {
        $result = $this->queries->run($dataset, $spec, $calculated, $user);

        if ($pivot === null) {
            return ['result' => $result, 'pivot' => null];
        }

        $layout = $this->pivots->validateLayout($pivot, $this->dimensionKeys($spec), $this->measureKeys($spec, $calculated));

        return ['result' => $result, 'pivot' => $this->pivots->build($result, $layout, $this->queries->additiveMeasures($dataset, $user))];
    }

    /**
     * Validates the input and returns the columns to store.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(User $user, array $data, ?BiSavedQuery $existing = null): array
    {
        $dataset = (string) $data['dataset'];
        $spec = (array) $data['spec'];
        $calculated = array_values(array_unique(array_map('strval', (array) ($data['calculated_measures'] ?? []))));
        $this->engine->datasetFor($dataset, $user);
        $this->queries->validate($dataset, $spec, $calculated, $user);
        $pivot = null;

        if (! empty($data['pivot'])) {
            $pivot = $this->pivots->validateLayout((array) $data['pivot'], $this->dimensionKeys($spec), $this->measureKeys($spec, $calculated));
        }

        $frequency = $data['snapshot_frequency'] ?? null;
        $nextSnapshot = $existing?->next_snapshot_on;

        if ($frequency === null) {
            $nextSnapshot = null;
        } elseif ($existing === null || $existing->snapshot_frequency !== $frequency || $nextSnapshot === null) {
            $nextSnapshot = $this->nextSnapshotAfter($this->clock->today(), (string) $frequency);
        }

        return [
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
            'dataset' => $dataset,
            'spec' => $spec,
            'calculated_measures' => $calculated,
            'pivot' => $pivot,
            'snapshot_frequency' => $frequency,
            'next_snapshot_on' => $nextSnapshot?->toDateString(),
        ];
    }

    /**
     * Dimension keys of a spec without the granularity suffix.
     *
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private function dimensionKeys(array $spec): array
    {
        return array_values(array_map(static fn (mixed $entry): string => explode(':', (string) $entry)[0], (array) ($spec['dimensions'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $calculated
     * @return list<string>
     */
    private function measureKeys(array $spec, array $calculated): array
    {
        return array_values(array_unique([...array_map('strval', (array) ($spec['measures'] ?? [])), ...$calculated]));
    }
}
