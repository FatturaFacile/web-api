<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Illuminate\Support\Facades\DB;
use Modules\DairyCheeseManufacturing\Domain\Enums\LabFormat;
use Modules\DairyCheeseManufacturing\Domain\Lab\LabParser;
use Modules\DairyCheeseManufacturing\Domain\Lab\QualityFlags;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\Breeder;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Models\QualityTest;

/**
 * Laboratory analyses: manual entry, bulk import (preview then commit), anomaly flags and the quarantine that a positive
 * inhibitor result puts on the milk of the breeder collected on the sample date.
 */
final class QualityTestService
{
    public function __construct(private readonly LabParser $parser = new LabParser) {}

    /**
     * @return list<string>
     */
    public function flags(QualityTest $test): array
    {
        /** @var array{scc_cells_ml: int, tbc_cfu_ml: int, freezing_point_c: float} $limits */
        $limits = config('dairy_cheese.quality_limits');

        return QualityFlags::evaluate(
            $test->inhibitors,
            $test->scc_cells_ml === null ? null : (int) $test->scc_cells_ml,
            $test->tbc_cfu_ml === null ? null : (int) $test->tbc_cfu_ml,
            $test->freezing_point_c === null ? null : (float) $test->freezing_point_c,
            $limits,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId = null): QualityTest
    {
        return DB::transaction(function () use ($data, $userId): QualityTest {
            $breeder = Breeder::query()->findOrFail((string) $data['breeder_id']);
            $sampleDate = (string) $data['sample_date'];

            $test = QualityTest::query()->create([
                'breeder_id' => $breeder->id,
                'delivery_id' => $data['delivery_id'] ?? null,
                'sample_date' => $sampleDate,
                'period_start' => $data['period_start'] ?? substr($sampleDate, 0, 8).'01',
                'period_end' => $data['period_end'] ?? date('Y-m-t', (int) strtotime($sampleDate)),
                'fat_pct' => $data['fat_pct'] ?? null,
                'protein_pct' => $data['protein_pct'] ?? null,
                'casein_pct' => $data['casein_pct'] ?? null,
                'urea_mg_l' => $data['urea_mg_l'] ?? null,
                'tbc_cfu_ml' => $data['tbc_cfu_ml'] ?? null,
                'scc_cells_ml' => $data['scc_cells_ml'] ?? null,
                'freezing_point_c' => $data['freezing_point_c'] ?? null,
                'inhibitors' => $data['inhibitors'] ?? 'not_tested',
                'laboratory' => $data['laboratory'] ?? null,
                'source_format' => $data['source_format'] ?? 'manual',
                'created_by' => $userId,
            ]);

            if ($test->inhibitors === 'positive') {
                $this->quarantine($test);
            }

            return $test;
        });
    }

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array{total: int, valid: int, anomalous: int, rejected: int}, committed: bool}
     */
    public function import(string $content, LabFormat $format, bool $commit, ?string $userId = null): array
    {
        $parsed = $this->parser->parse($content, $format);

        if ($parsed === []) {
            throw new DairyCheeseException('The file holds no rows.', 'import_empty');
        }

        $rows = [];
        $valid = $anomalous = $rejected = 0;
        $seen = [];

        foreach ($parsed as $row) {
            $errors = $row['errors'];
            $breeder = in_array('breeder_identifier_missing', $errors, true) || in_array('xml_invalid', $errors, true) ? null : $this->resolveBreeder($row['identifiers']);

            if ($breeder === null && $row['identifiers'] !== []) {
                $errors[] = 'breeder_not_found';
            }

            $data = $row['data'];
            $key = $breeder === null ? null : $breeder->id.'|'.($data['sample_date'] ?? '');

            if ($breeder !== null && $errors === [] && ($key !== null && (isset($seen[$key]) || QualityTest::query()->where('breeder_id', $breeder->id)->whereDate('sample_date', (string) $data['sample_date'])->exists()))) {
                $errors[] = 'duplicate_test';
            }

            if ($key !== null) {
                $seen[$key] = true;
            }

            $limits = config('dairy_cheese.quality_limits');
            $anomalies = $data === [] ? [] : QualityFlags::evaluate(
                $data['inhibitors'] ?? null,
                $data['scc_cells_ml'] ?? null,
                $data['tbc_cfu_ml'] ?? null,
                $data['freezing_point_c'] ?? null,
                $limits,
            );

            $rows[] = [
                'line' => $row['line'],
                'parsed' => $data + ['breeder_id' => $breeder?->id, 'breeder_name' => $breeder?->name, 'source_format' => $format->value, 'flags' => $anomalies],
                'anomalies' => $anomalies,
                'errors' => $errors,
            ];

            if ($errors !== []) {
                $rejected++;
            } else {
                $valid++;
                $anomalous += $anomalies === [] ? 0 : 1;
            }
        }

        if ($commit) {
            DB::transaction(function () use ($rows, $userId): void {
                foreach ($rows as $row) {
                    if ($row['errors'] === []) {
                        $attributes = $row['parsed'];
                        unset($attributes['breeder_name'], $attributes['flags']);
                        $this->create($attributes, $userId);
                    }
                }
            });
        }

        return ['rows' => $rows, 'summary' => ['total' => count($rows), 'valid' => $valid, 'anomalous' => $anomalous, 'rejected' => $rejected], 'committed' => $commit];
    }

    /**
     * The milk of the breeder collected on the sample date is held back until a quality manager releases or rejects it.
     */
    public function quarantine(QualityTest $test): int
    {
        return MilkDelivery::query()
            ->where('breeder_id', $test->breeder_id)
            ->whereDate('collection_date', $test->sample_date->toDateString())
            ->whereIn('status', ['registered', 'accepted'])
            ->update(['status' => 'quarantined']);
    }

    /**
     * @param  array<string, string>  $identifiers
     */
    private function resolveBreeder(array $identifiers): ?Breeder
    {
        $columns = ['code' => 'code', 'tvd' => 'tvd_number', 'bdm' => 'bdm_number', 'cuaa' => 'cuaa', 'asl' => 'asl_code', 'farm' => 'farm_code'];

        foreach ($columns as $key => $column) {
            if (isset($identifiers[$key])) {
                $breeder = Breeder::query()->where($column, $identifiers[$key])->first();

                if ($breeder !== null) {
                    return $breeder;
                }
            }
        }

        return null;
    }
}
