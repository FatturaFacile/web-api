<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\QmsInstrument;
use Modules\Quality\Models\QmsSpcCharacteristic;
use Modules\Quality\Models\QmsSpcSample;

/**
 * Statistical process control on subgroup samples: X-bar/R control charts, process capability (Cp, Cpk from the
 * within-subgroup sigma; Pp, Ppk from the overall sigma) and the Nelson out-of-control rules.
 *
 * Rules on the X-bar chart (the point that completes the pattern is reported):
 *   1 one point beyond the control limits (3 sigma), 2 nine in a row on one side of the center line, 3 six in a row
 *   steadily increasing or decreasing, 4 fourteen in a row alternating up and down, 5 two of three beyond 2 sigma on
 *   the same side, 6 four of five beyond 1 sigma on the same side. The R chart uses rule 1 only.
 */
final readonly class SpcService
{
    /** Minimum number of subgroups the textbooks ask before the limits and the indices are reliable. */
    public const int ReliableSubgroups = 20;

    /** Shewhart constants by subgroup size n (2 to 10). */
    private const array A2 = [2 => 1.880, 3 => 1.023, 4 => 0.729, 5 => 0.577, 6 => 0.483, 7 => 0.419, 8 => 0.373, 9 => 0.337, 10 => 0.308];

    private const array D3 = [2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0, 7 => 0.076, 8 => 0.136, 9 => 0.184, 10 => 0.223];

    private const array D4 = [2 => 3.267, 3 => 2.574, 4 => 2.282, 5 => 2.114, 6 => 2.004, 7 => 1.924, 8 => 1.864, 9 => 1.816, 10 => 1.777];

    private const array D2 = [2 => 1.128, 3 => 1.693, 4 => 2.059, 5 => 2.326, 6 => 2.534, 7 => 2.704, 8 => 2.847, 9 => 2.970, 10 => 3.078];

    public function __construct(private QualityLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCharacteristic(string $tenantId, array $data): QmsSpcCharacteristic
    {
        $this->assertCodeFree($tenantId, (string) $data['code'], null);
        $this->assertSpecLimits($data['lower_spec_limit'] ?? null, $data['upper_spec_limit'] ?? null);
        $this->assertInstrument($tenantId, $data['instrument_id'] ?? null);

        return QmsSpcCharacteristic::create([...$this->characteristicFields($data), 'tenant_id' => $tenantId, 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCharacteristic(QmsSpcCharacteristic $characteristic, array $data): QmsSpcCharacteristic
    {
        if (isset($data['code'])) {
            $this->assertCodeFree($characteristic->tenant_id, (string) $data['code'], $characteristic->id);
        }
        if (isset($data['subgroup_size']) && (int) $data['subgroup_size'] !== $characteristic->subgroup_size && $characteristic->samples()->exists()) {
            throw ValidationException::withMessages(['subgroup_size' => [__('quality_errors.spc.subgroup_locked')]]);
        }
        $this->assertSpecLimits($data['lower_spec_limit'] ?? $characteristic->lower_spec_limit, $data['upper_spec_limit'] ?? $characteristic->upper_spec_limit);
        $this->assertInstrument($characteristic->tenant_id, $data['instrument_id'] ?? null);
        $characteristic->update([...$this->characteristicFields($data), ...array_intersect_key($data, ['status' => true])]);

        return $characteristic->refresh();
    }

    /**
     * Records one subgroup. Returns the sample and the rule violations that involve it.
     *
     * @param  array<string, mixed>  $data
     * @return array{sample: QmsSpcSample, violations: list<array<string, mixed>>}
     */
    public function addSample(QmsSpcCharacteristic $characteristic, array $data, ?string $actorId): array
    {
        if ($characteristic->status !== 'active') {
            throw ValidationException::withMessages(['characteristic_id' => [__('quality_errors.spc.archived')]]);
        }
        $values = array_map(static fn (mixed $value): float => (float) $value, array_values($data['measurements']));
        if (count($values) !== $characteristic->subgroup_size) {
            throw ValidationException::withMessages(['measurements' => [__('quality_errors.spc.subgroup_size', ['size' => $characteristic->subgroup_size])]]);
        }
        $instrumentId = $data['instrument_id'] ?? $characteristic->instrument_id;
        $this->assertInstrument($characteristic->tenant_id, $instrumentId);

        $sample = DB::transaction(fn (): QmsSpcSample => QmsSpcSample::create([
            'tenant_id' => $characteristic->tenant_id,
            'characteristic_id' => $characteristic->id,
            'sampled_at' => $data['sampled_at'] ?? now(),
            'lot' => $data['lot'] ?? null,
            'measurements' => $values,
            'mean' => round(array_sum($values) / count($values), 5),
            'range' => round(max($values) - min($values), 5),
            'instrument_id' => $instrumentId,
            'recorded_by' => $actorId,
        ]));

        $violations = array_values(array_filter(
            $this->chart($characteristic)['violations'],
            static fn (array $violation): bool => $violation['sample_id'] === $sample->id,
        ));
        if ($violations !== []) {
            $this->ledger->record('QMS_SPC_OUT_OF_CONTROL', $sample, ['rules' => array_column($violations, 'rule')]);
        }

        return ['sample' => $sample, 'violations' => $violations];
    }

    /**
     * Control chart data of a characteristic over its latest subgroups.
     *
     * @return array<string, mixed>
     */
    public function chart(QmsSpcCharacteristic $characteristic, int $limit = 100): array
    {
        $samples = QmsSpcSample::query()
            ->where('characteristic_id', $characteristic->id)
            ->orderByDesc('sampled_at')->orderByDesc('id')
            ->limit(max(2, $limit))->get()
            ->sortBy([['sampled_at', 'asc'], ['id', 'asc']])->values();

        $size = $characteristic->subgroup_size;
        $points = $samples->map(static fn (QmsSpcSample $sample): array => [
            'sample_id' => $sample->id,
            'sampled_at' => $sample->sampled_at->toIso8601String(),
            'lot' => $sample->lot,
            'mean' => (float) $sample->mean,
            'range' => (float) $sample->range,
        ])->all();

        $result = [
            'characteristic_id' => $characteristic->id,
            'subgroup_size' => $size,
            'subgroup_count' => count($points),
            'is_reliable' => count($points) >= self::ReliableSubgroups,
            'points' => $points,
            'limits' => null,
            'capability' => null,
            'violations' => [],
            'in_control' => true,
        ];
        if (count($points) < 2 || ! isset(self::A2[$size])) {
            return $result;
        }

        $means = array_column($points, 'mean');
        $ranges = array_column($points, 'range');
        $grandMean = array_sum($means) / count($means);
        $meanRange = array_sum($ranges) / count($ranges);
        $limits = [
            'xbar' => ['center' => $grandMean, 'ucl' => $grandMean + self::A2[$size] * $meanRange, 'lcl' => $grandMean - self::A2[$size] * $meanRange],
            'range' => ['center' => $meanRange, 'ucl' => self::D4[$size] * $meanRange, 'lcl' => self::D3[$size] * $meanRange],
        ];

        $all = [];
        foreach ($samples as $sample) {
            foreach ($sample->measurements as $value) {
                $all[] = (float) $value;
            }
        }
        $result['limits'] = array_map(static fn (array $set): array => array_map(static fn (float $value): float => round($value, 5), $set), $limits);
        $result['capability'] = $this->capability($characteristic, $grandMean, $meanRange / self::D2[$size], $all);
        $result['violations'] = $this->violations($points, $limits);
        $result['in_control'] = $result['violations'] === [];

        return $result;
    }

    /**
     * @param  list<float>  $values  every individual measurement
     * @return array<string, float|int|null>
     */
    private function capability(QmsSpcCharacteristic $characteristic, float $mean, float $sigmaWithin, array $values): array
    {
        $lower = $characteristic->lower_spec_limit !== null ? (float) $characteristic->lower_spec_limit : null;
        $upper = $characteristic->upper_spec_limit !== null ? (float) $characteristic->upper_spec_limit : null;
        $count = count($values);
        $overallMean = array_sum($values) / $count;
        $sigmaOverall = $count > 1 ? sqrt(array_sum(array_map(static fn (float $v): float => ($v - $overallMean) ** 2, $values)) / ($count - 1)) : 0.0;

        $indices = static function (float $sigma, float $center) use ($lower, $upper): array {
            if ($sigma <= 0.0) {
                return [null, null];
            }
            $spread = $lower !== null && $upper !== null ? round(($upper - $lower) / (6 * $sigma), 3) : null;
            $sides = array_filter([$upper !== null ? ($upper - $center) / (3 * $sigma) : null, $lower !== null ? ($center - $lower) / (3 * $sigma) : null], static fn (?float $v): bool => $v !== null);

            return [$spread, $sides === [] ? null : round(min($sides), 3)];
        };
        [$cp, $cpk] = $indices($sigmaWithin, $mean);
        [$pp, $ppk] = $indices($sigmaOverall, $overallMean);

        return [
            'mean' => round($mean, 5),
            'sigma_within' => round($sigmaWithin, 6),
            'sigma_overall' => round($sigmaOverall, 6),
            'cp' => $cp,
            'cpk' => $cpk,
            'pp' => $pp,
            'ppk' => $ppk,
            'measurement_count' => $count,
        ];
    }

    /**
     * @param  list<array{sample_id: string, mean: float, range: float}>  $points
     * @param  array{xbar: array<string, float>, range: array<string, float>}  $limits
     * @return list<array{sample_id: string, index: int, chart: string, rule: int}>
     */
    private function violations(array $points, array $limits): array
    {
        $found = [];
        $means = array_column($points, 'mean');
        $center = $limits['xbar']['center'];
        $sigma = ($limits['xbar']['ucl'] - $center) / 3;

        foreach ($points as $index => $point) {
            if ($point['range'] > $limits['range']['ucl'] || ($limits['range']['lcl'] > 0 && $point['range'] < $limits['range']['lcl'])) {
                $found[] = ['sample_id' => $point['sample_id'], 'index' => $index, 'chart' => 'range', 'rule' => 1];
            }
        }
        if ($sigma <= 0.0) {
            return $found;
        }

        $z = array_map(static fn (float $mean): float => ($mean - $center) / $sigma, $means);
        $flag = static function (int $index, int $rule) use (&$found, $points): void {
            $found[] = ['sample_id' => $points[$index]['sample_id'], 'index' => $index, 'chart' => 'xbar', 'rule' => $rule];
        };

        foreach ($z as $i => $value) {
            if (abs($value) > 3) {
                $flag($i, 1);
            }
            if ($i >= 8 && $this->allSatisfy(array_slice($z, $i - 8, 9), static fn (float $v): bool => $v > 0) || ($i >= 8 && $this->allSatisfy(array_slice($z, $i - 8, 9), static fn (float $v): bool => $v < 0))) {
                $flag($i, 2);
            }
            if ($i >= 5 && $this->monotonic(array_slice($z, $i - 5, 6))) {
                $flag($i, 3);
            }
            if ($i >= 13 && $this->alternating(array_slice($z, $i - 13, 14))) {
                $flag($i, 4);
            }
            foreach ([1, -1] as $side) {
                if ($side * $value > 2 && count(array_filter(array_slice($z, max(0, $i - 2), min(3, $i + 1)), static fn (float $v): bool => $side * $v > 2)) >= 2) {
                    $flag($i, 5);
                }
                if ($side * $value > 1 && count(array_filter(array_slice($z, max(0, $i - 4), min(5, $i + 1)), static fn (float $v): bool => $side * $v > 1)) >= 4) {
                    $flag($i, 6);
                }
            }
        }

        usort($found, static fn (array $a, array $b): int => [$a['index'], $a['chart'], $a['rule']] <=> [$b['index'], $b['chart'], $b['rule']]);

        return $found;
    }

    /**
     * @param  list<float>  $values
     * @param  callable(float): bool  $test
     */
    private function allSatisfy(array $values, callable $test): bool
    {
        foreach ($values as $value) {
            if (! $test($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<float>  $values
     */
    private function monotonic(array $values): bool
    {
        $up = $down = true;
        for ($i = 1; $i < count($values); $i++) {
            $up = $up && $values[$i] > $values[$i - 1];
            $down = $down && $values[$i] < $values[$i - 1];
        }

        return $up || $down;
    }

    /**
     * @param  list<float>  $values
     */
    private function alternating(array $values): bool
    {
        for ($i = 2; $i < count($values); $i++) {
            $previous = $values[$i - 1] - $values[$i - 2];
            $current = $values[$i] - $values[$i - 1];
            if ($previous * $current >= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function characteristicFields(array $data): array
    {
        return array_intersect_key($data, array_flip(['code', 'name', 'unit', 'lower_spec_limit', 'upper_spec_limit', 'target', 'subgroup_size', 'instrument_id', 'notes']));
    }

    private function assertSpecLimits(mixed $lower, mixed $upper): void
    {
        if ($lower !== null && $upper !== null && (float) $lower >= (float) $upper) {
            throw ValidationException::withMessages(['upper_spec_limit' => [__('quality_errors.spc.limits_order')]]);
        }
    }

    private function assertCodeFree(string $tenantId, string $code, ?string $ignoreId): void
    {
        $taken = QmsSpcCharacteristic::query()->where('tenant_id', $tenantId)->where('code', $code)
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => [__('quality_errors.spc.code_exists')]]);
        }
    }

    private function assertInstrument(string $tenantId, mixed $instrumentId): void
    {
        if ($instrumentId !== null && ! QmsInstrument::query()->where('tenant_id', $tenantId)->whereKey($instrumentId)->exists()) {
            throw ValidationException::withMessages(['instrument_id' => [__('quality_errors.instrument.not_found')]]);
        }
    }
}
