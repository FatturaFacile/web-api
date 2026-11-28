<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Lab;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns the raw text values of one laboratory row into typed analysis values, converting units (thousands per ml, m°C) and
 * collecting the reasons a row cannot be used.
 */
final class LabRowNormalizer
{
    /**
     * @param  array<string, string>  $raw  field => text value
     * @return array{line: int, identifiers: array<string, string>, data: array<string, mixed>, errors: list<string>}
     */
    public function normalize(array $raw, LabFormatProfile $profile, int $line): array
    {
        $errors = [];
        $identifiers = [];

        foreach (['code', 'tvd', 'bdm', 'cuaa', 'asl', 'farm'] as $key) {
            if (isset($raw[$key]) && trim($raw[$key]) !== '') {
                $identifiers[$key] = trim($raw[$key]);
            }
        }

        if ($identifiers === []) {
            $errors[] = 'breeder_identifier_missing';
        }

        $sampleDate = $this->date($raw['sample_date'] ?? null);

        if ($sampleDate === null) {
            $errors[] = 'sample_date_invalid';
        }

        $fat = $this->decimal($raw['fat_pct'] ?? null, 'fat_pct', $errors, 0.0, 15.0);
        $protein = $this->decimal($raw['protein_pct'] ?? null, 'protein_pct', $errors, 0.0, 12.0);
        $casein = $this->decimal($raw['casein_pct'] ?? null, 'casein_pct', $errors, 0.0, 10.0);
        $urea = $this->decimal($raw['urea_mg_l'] ?? null, 'urea_mg_l', $errors, 0.0, 1000.0);
        $tbc = $this->count($raw['tbc_cfu_ml'] ?? null, $profile->thousandsCounts, 'tbc_cfu_ml', $errors);
        $scc = $this->count($raw['scc_cells_ml'] ?? null, $profile->thousandsCounts, 'scc_cells_ml', $errors);
        $freezing = $this->freezingPoint($raw['freezing_point_c'] ?? null, $errors);
        $inhibitors = $this->inhibitors($raw['inhibitors'] ?? null, $errors);

        if ($errors === [] && [$fat, $protein, $casein, $urea, $tbc, $scc, $freezing] === [null, null, null, null, null, null, null] && $inhibitors === 'not_tested') {
            $errors[] = 'no_measurements';
        }

        $periodStart = $this->date($raw['period_start'] ?? null);
        $periodEnd = $this->date($raw['period_end'] ?? null);

        return [
            'line' => $line,
            'identifiers' => $identifiers,
            'data' => [
                'sample_date' => $sampleDate?->toDateString(),
                'period_start' => ($periodStart ?? $sampleDate?->startOfMonth())?->toDateString(),
                'period_end' => ($periodEnd ?? $sampleDate?->endOfMonth())?->toDateString(),
                'fat_pct' => $fat, 'protein_pct' => $protein, 'casein_pct' => $casein, 'urea_mg_l' => $urea,
                'tbc_cfu_ml' => $tbc, 'scc_cells_ml' => $scc, 'freezing_point_c' => $freezing, 'inhibitors' => $inhibitors,
                'laboratory' => isset($raw['laboratory']) && trim($raw['laboratory']) !== '' ? trim($raw['laboratory']) : null,
            ],
            'errors' => $errors,
        ];
    }

    public function date(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'Ymd'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $errors
     */
    private function decimal(?string $value, string $field, array &$errors, float $min, float $max): ?float
    {
        $number = $this->number($value);

        if ($number === null) {
            if (trim((string) $value) !== '') {
                $errors[] = $field.'_invalid';
            }

            return null;
        }

        if ($number < $min || $number > $max) {
            $errors[] = $field.'_out_of_range';

            return null;
        }

        return round($number, 3);
    }

    /**
     * @param  list<string>  $errors
     */
    private function count(?string $value, bool $thousands, string $field, array &$errors): ?int
    {
        $number = $this->number($value);

        if ($number === null) {
            if (trim((string) $value) !== '') {
                $errors[] = $field.'_invalid';
            }

            return null;
        }

        if ($number < 0) {
            $errors[] = $field.'_out_of_range';

            return null;
        }

        return (int) round($thousands ? $number * 1000 : $number);
    }

    /**
     * @param  list<string>  $errors
     */
    private function freezingPoint(?string $value, array &$errors): ?float
    {
        $number = $this->number($value);

        if ($number === null) {
            if (trim((string) $value) !== '') {
                $errors[] = 'freezing_point_c_invalid';
            }

            return null;
        }

        // A value of ten or more can only be milli-degrees (m°C): the freezing point of milk is about -0.52 °C.
        $celsius = abs($number) >= 10 ? $number / 1000 : $number;
        $celsius = $celsius > 0 ? -$celsius : $celsius;

        if ($celsius < -1.5) {
            $errors[] = 'freezing_point_c_out_of_range';

            return null;
        }

        return round($celsius, 4);
    }

    /**
     * @param  list<string>  $errors
     */
    private function inhibitors(?string $value, array &$errors): string
    {
        $text = strtolower(trim((string) $value));

        if ($text === '' || in_array($text, ['n/a', 'nd', '-', 'non testato', 'nicht geprueft'], true)) {
            return 'not_tested';
        }

        if (in_array($text, ['negativ', 'negativo', 'negative', 'neg', 'negatif', 'n', 'no', '0', 'nein', 'non'], true)) {
            return 'negative';
        }

        if (in_array($text, ['positiv', 'positivo', 'positive', 'pos', 'positif', 'p', 'y', 'yes', 'si', 'sì', '1', 'ja', 'oui'], true)) {
            return 'positive';
        }

        $errors[] = 'inhibitors_invalid';

        return 'not_tested';
    }

    private function number(?string $value): ?float
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $text = str_replace(' ', '', $text);

        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = str_replace(strrpos($text, ',') > strrpos($text, '.') ? '.' : ',', '', $text);
        }

        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? (float) $text : null;
    }
}
