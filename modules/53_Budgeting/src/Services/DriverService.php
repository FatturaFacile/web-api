<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Models\BudgetDriver;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * Driver-based planning: a quantity times a rate, spread over a range of periods, writes the budget lines of an account
 * (and cost centre and project). Generating a driver again replaces its previous lines; a deactivated driver writes
 * none.
 */
final readonly class DriverService
{
    public function __construct(private VersionService $versions, private LineService $lines, private SpreadService $spread) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(BudgetVersion $version, array $data): BudgetDriver
    {
        $this->versions->assertDraft($version);
        $this->assertValid($version, $data);

        return BudgetDriver::create([...$this->fields($data), 'tenant_id' => $version->tenant_id, 'version_id' => $version->id])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BudgetDriver $driver, array $data): BudgetDriver
    {
        $version = BudgetVersion::query()->findOrFail($driver->version_id);
        $this->versions->assertDraft($version);
        $merged = [...$driver->only(['account_id', 'cost_center_id', 'project_id', 'quantity', 'rate', 'spread_method', 'percentages', 'start_period', 'end_period']), ...$data];
        $this->assertValid($version, $merged);

        $driver->update($this->fields($data));

        return $driver->refresh();
    }

    public function delete(BudgetDriver $driver): void
    {
        $this->versions->assertDraft(BudgetVersion::query()->findOrFail($driver->version_id));

        DB::transaction(function () use ($driver): void {
            BudgetLine::query()->where('driver_id', $driver->id)->get()->each->delete();
            $driver->delete();
        });
    }

    /**
     * Writes the budget lines of the driver (the amount is quantity times rate) and returns them.
     *
     * @return list<BudgetLine>
     */
    public function generate(BudgetDriver $driver): array
    {
        $version = BudgetVersion::query()->findOrFail($driver->version_id);

        return DB::transaction(function () use ($driver, $version): array {
            $this->versions->assertDraft($version);
            BudgetLine::query()->where('driver_id', $driver->id)->get()->each->delete();

            if (! $driver->is_active) {
                return [];
            }

            $this->assertValid($version, $driver->only(['account_id', 'cost_center_id', 'project_id', 'quantity', 'rate', 'spread_method', 'percentages', 'start_period', 'end_period']));
            $total = bcround(bcmul((string) $driver->quantity, (string) $driver->rate, 6), 2);
            $amounts = $this->spread->spread($total, $driver->start_period, $driver->end_period, $driver->spread_method, $version->seasonal_weights, $driver->percentages);

            $lines = [];
            foreach ($amounts as $period => $amount) {
                $lines[] = $this->lines->upsert($version, $driver->only(['account_id', 'cost_center_id', 'project_id']), $period, $amount, BudgetLine::Driver, $driver->id, $driver->name);
            }

            return $lines;
        });
    }

    /**
     * Generates every active driver of a version.
     *
     * @return array{drivers: int, lines: int}
     */
    public function generateAll(BudgetVersion $version): array
    {
        $this->versions->assertDraft($version);
        $count = 0;
        $lines = 0;

        foreach (BudgetDriver::query()->where('version_id', $version->id)->where('is_active', true)->orderBy('created_at')->get() as $driver) {
            $lines += count($this->generate($driver));
            $count++;
        }

        return ['drivers' => $count, 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertValid(BudgetVersion $version, array $data): void
    {
        $this->lines->assertDimensions($version, [...$data, 'period' => $data['start_period'] ?? 1]);
        $start = (int) ($data['start_period'] ?? 1);
        $end = (int) ($data['end_period'] ?? BudgetPeriods::Count);

        if ($start > $end || $end > BudgetPeriods::Count) {
            throw ValidationException::withMessages(['end_period' => [__('budgeting_errors.spread.range')]]);
        }

        $method = (string) ($data['spread_method'] ?? BudgetDriver::Even);

        if ($method === BudgetDriver::Seasonal) {
            $this->spread->spread('1', $start, $end, $method, $version->seasonal_weights, null);
        } elseif ($method === BudgetDriver::Percentage) {
            $this->spread->spread('1', $start, $end, $method, null, $data['percentages'] ?? null);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['name', 'account_id', 'cost_center_id', 'project_id', 'quantity', 'rate', 'spread_method', 'percentages', 'start_period', 'end_period', 'is_active']));

        if (($fields['spread_method'] ?? null) !== BudgetDriver::Percentage && array_key_exists('spread_method', $fields)) {
            $fields['percentages'] = null;
        }

        return $fields;
    }
}
