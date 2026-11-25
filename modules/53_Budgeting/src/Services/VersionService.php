<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Models\BudgetDriver;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * Budget versions of a fiscal year: creation, editing of a draft, deletion and copies (a forecast starts as a copy of a
 * budget). A version that left the draft is never edited or deleted.
 */
final readonly class VersionService
{
    public function __construct(private SpreadService $spread) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): BudgetVersion
    {
        $data['year_start'] ??= Carbon::create((int) $data['fiscal_year'], 1, 1)->toDateString();
        $this->assertFields($tenantId, $data, null);

        return BudgetVersion::create([
            ...$this->fields($data),
            'tenant_id' => $tenantId,
            'version_type' => $data['version_type'] ?? BudgetVersion::Budget,
            'status' => BudgetVersion::Draft,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BudgetVersion $version, array $data): BudgetVersion
    {
        $this->assertDraft($version);
        $this->assertFields($version->tenant_id, [...$version->only(['fiscal_year', 'name', 'seasonal_weights']), ...$data], $version);
        $version->update($this->fields($data));

        return $version->refresh();
    }

    public function delete(BudgetVersion $version): void
    {
        $this->assertDraft($version);

        DB::transaction(function () use ($version): void {
            $version->lines()->get()->each->delete();
            $version->drivers()->get()->each->delete();
            $version->delete();
        });
    }

    /**
     * A draft copy of a version with its lines and drivers, as a new budget or as a forecast.
     *
     * @param  array<string, mixed>  $data
     */
    public function copy(BudgetVersion $source, array $data): BudgetVersion
    {
        $this->assertFields($source->tenant_id, ['fiscal_year' => $source->fiscal_year, 'name' => $data['name']], null);

        return DB::transaction(function () use ($source, $data): BudgetVersion {
            $copy = BudgetVersion::create([
                'tenant_id' => $source->tenant_id,
                'name' => $data['name'],
                'description' => $data['description'] ?? $source->description,
                'fiscal_year' => $source->fiscal_year,
                'year_start' => $source->year_start->toDateString(),
                'version_type' => $data['version_type'] ?? BudgetVersion::Forecast,
                'status' => BudgetVersion::Draft,
                'currency' => $source->currency,
                'granularity' => $source->granularity,
                'scenario_kind' => $data['scenario_kind'] ?? null,
                'revision_number' => $data['revision_number'] ?? 0,
                'revision_reason' => $data['revision_reason'] ?? null,
                'base_version_id' => $source->id,
                'seasonal_weights' => $source->seasonal_weights,
            ]);

            $driverMap = [];
            foreach ($source->drivers()->get() as $driver) {
                $driverMap[$driver->id] = BudgetDriver::create([
                    'tenant_id' => $source->tenant_id,
                    'version_id' => $copy->id,
                    'name' => $driver->name,
                    'account_id' => $driver->account_id,
                    'cost_center_id' => $driver->cost_center_id,
                    'project_id' => $driver->project_id,
                    'quantity' => $driver->quantity,
                    'rate' => $driver->rate,
                    'spread_method' => $driver->spread_method,
                    'percentages' => $driver->percentages,
                    'start_period' => $driver->start_period,
                    'end_period' => $driver->end_period,
                    'is_active' => $driver->is_active,
                ])->id;
            }

            foreach ($source->lines()->get() as $line) {
                BudgetLine::create([
                    'tenant_id' => $source->tenant_id,
                    'version_id' => $copy->id,
                    'account_id' => $line->account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'project_id' => $line->project_id,
                    'period' => $line->period,
                    'amount' => $line->amount,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'source' => $line->source,
                    'driver_id' => $line->driver_id === null ? null : ($driverMap[$line->driver_id] ?? null),
                    'notes' => $line->notes,
                ]);
            }

            return $copy->refresh();
        });
    }

    public function assertDraft(BudgetVersion $version): void
    {
        if (! $version->isDraft()) {
            throw ValidationException::withMessages(['status' => [__('budgeting_errors.version.not_draft')]]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertFields(string $tenantId, array $data, ?BudgetVersion $current): void
    {
        $taken = BudgetVersion::query()
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year', $data['fiscal_year'])
            ->where('name', $data['name'])
            ->when($current !== null, static fn ($query) => $query->whereKeyNot($current->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => [__('budgeting_errors.version.name_taken', ['year' => $data['fiscal_year']])]]);
        }

        if (array_key_exists('seasonal_weights', $data) && $data['seasonal_weights'] !== null) {
            $this->spread->assertSeasonalWeights($data['seasonal_weights']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['name', 'description', 'fiscal_year', 'year_start', 'version_type', 'scenario_kind', 'granularity', 'currency', 'seasonal_weights']));

        if (($fields['granularity'] ?? null) === null) {
            unset($fields['granularity']);
        }

        if (($fields['version_type'] ?? null) !== null && $fields['version_type'] !== BudgetVersion::Scenario) {
            $fields['scenario_kind'] = null;
        }

        if (isset($fields['seasonal_weights'])) {
            $fields['seasonal_weights'] = $this->spread->assertSeasonalWeights($fields['seasonal_weights']);
        }

        return $fields;
    }
}
