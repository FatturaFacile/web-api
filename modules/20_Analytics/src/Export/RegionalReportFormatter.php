<?php

declare(strict_types=1);

namespace Modules\Analytics\Export;

use Illuminate\Support\Facades\Lang;

/**
 * Formats the cells and headers of an exported report for a regional locale. Numbers are formatted from their decimal
 * strings (never through floats), so what the report computed is exactly what is printed.
 *
 * - `it-CH`, `de-CH`, `fr-CH`: apostrophe thousands, dot decimal (`1'234.50`), dates `dd.MM.yyyy`, `;` in CSV.
 * - `it-IT`: dot thousands, comma decimal (`1.234,50`), dates `dd/MM/yyyy`, `;` in CSV.
 * - `en`: comma thousands, dot decimal (`1,234.50`), dates `yyyy-MM-dd`, `,` in CSV.
 */
final readonly class RegionalReportFormatter
{
    public const array Locales = ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en'];

    public string $locale;

    public function __construct(?string $locale)
    {
        $this->locale = self::normalise($locale);
    }

    /**
     * A supported locale for a user locale or language code.
     */
    public static function normalise(?string $locale): string
    {
        $locale = str_replace('_', '-', trim((string) $locale));

        return match (true) {
            in_array($locale, self::Locales, true) => $locale,
            str_starts_with($locale, 'it') => 'it-IT',
            str_starts_with($locale, 'de') => 'de-CH',
            str_starts_with($locale, 'fr') => 'fr-CH',
            default => 'en',
        };
    }

    /**
     * The language of the translations (it, de, fr or en).
     */
    public function language(): string
    {
        return substr($this->locale, 0, 2);
    }

    public function delimiter(): string
    {
        return $this->locale === 'en' ? ',' : ';';
    }

    /**
     * Excel date format code of the locale.
     */
    public function dateMask(): string
    {
        return match ($this->locale) {
            'it-IT' => 'dd/mm/yyyy',
            'en' => 'yyyy-mm-dd',
            default => 'dd.mm.yyyy',
        };
    }

    public function dateTime(\DateTimeInterface $value): string
    {
        return $value->format(match ($this->locale) {
            'it-IT' => 'd/m/Y H:i',
            'en' => 'Y-m-d H:i',
            default => 'd.m.Y H:i',
        });
    }

    public function date(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match) !== 1) {
            return $value;
        }

        return match ($this->locale) {
            'it-IT' => "{$match[3]}/{$match[2]}/{$match[1]}",
            'en' => "{$match[1]}-{$match[2]}-{$match[3]}",
            default => "{$match[3]}.{$match[2]}.{$match[1]}",
        };
    }

    /**
     * A decimal string with the separators of the locale and exactly `$scale` decimals.
     */
    public function number(string|int|float $value, int $scale = 2): string
    {
        $value = is_float($value) ? number_format($value, $scale, '.', '') : (string) $value;

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $match) !== 1) {
            return $value;
        }

        [$thousands, $decimal] = match ($this->locale) {
            'it-IT' => ['.', ','],
            'en' => [',', '.'],
            default => ["'", '.'],
        };
        $integer = strrev(implode($thousands, str_split(strrev($match[2]), 3)));
        $fraction = $scale > 0 ? $decimal.str_pad(substr($match[3] ?? '', 0, $scale), $scale, '0') : '';
        $negative = $match[1] === '-' && trim($match[2].substr($match[3] ?? '', 0, $scale), '0') !== '' ? '-' : '';

        return $negative.$integer.$fraction;
    }

    /**
     * An amount with its currency: `CHF 1'234.50`, `1.234,50 €`, `EUR 1,234.50`.
     */
    public function money(string|int|float $value, string $currency): string
    {
        $amount = $this->number($value, 2);

        return match (true) {
            $this->locale === 'it-IT' && strtoupper($currency) === 'EUR' => $amount.' €',
            default => strtoupper($currency).' '.$amount,
        };
    }

    public function percent(string|int|float $value): string
    {
        return $this->number($value, 2).' %';
    }

    /**
     * One cell as text for CSV and PDF, from the column declaration of the report.
     *
     * @param  array<string, mixed>  $column
     */
    public function cell(array $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return (string) trans($value ? 'analytics.export.yes' : 'analytics.export.no', [], $this->language());
        }

        $kind = $column['kind'] ?? 'measure';

        if ($kind === 'dimension') {
            return match ($column['type'] ?? 'string') {
                'date' => $this->date((string) $value),
                'number' => is_numeric($value) ? $this->number((string) $value, 2) : (string) $value,
                default => (string) $value,
            };
        }

        if (! is_numeric($value)) {
            return (string) $value;
        }

        return match ($column['unit'] ?? 'count') {
            'count' => $this->number((string) $value, 0),
            'quantity' => $this->number((string) $value, 4),
            'percent' => $this->percent((string) $value),
            default => $this->number((string) $value, 2),
        };
    }

    /**
     * The header of a column in the language of the locale: the translation of the field of the dataset, or the
     * declared label when the field has none.
     *
     * @param  array<string, mixed>  $column
     */
    public function header(string $dataset, array $column): string
    {
        $key = (string) $column['key'];

        foreach (['__change_percent' => 'change_percent', '__previous' => 'previous', '__change' => 'change'] as $suffix => $word) {
            if (str_ends_with($key, $suffix)) {
                $original = substr($key, 0, -strlen($suffix));
                $label = $this->field($dataset, $original) ?? (string) preg_replace('/ \((previous|change|change %)\)$/', '', (string) ($column['label'] ?? $key));

                return $label.' ('.trans("analytics.export.{$word}", [], $this->language()).')';
            }
        }

        return $this->field($dataset, $key) ?? (string) ($column['label'] ?? $key);
    }

    /**
     * The translation of a field of a dataset: the one of the dataset, else the common one, else null.
     */
    private function field(string $dataset, string $key): ?string
    {
        foreach (["analytics.fields.{$dataset}.{$key}", "analytics.fields.common.{$key}"] as $translation) {
            if (Lang::has($translation, $this->language(), false)) {
                return (string) trans($translation, [], $this->language());
            }
        }

        return null;
    }
}
