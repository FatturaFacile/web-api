<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Services;

use XMLWriter;

/**
 * Builds the SIAN telemetry batch of the oil register as XML (the transmission file) or CSV (the printable extract) from plain rows.
 *
 * Each row: progressive, operation_date, causal, from_tank, to_tank, counterparty_cuaa, quantity_kg, quantity_l, category, origin, bottle_format,
 * bottle_count, package_lot, best_before.
 */
final class SianFileBuilder
{
    public const array COLUMNS = [
        'progressive', 'operation_date', 'causal', 'from_tank', 'to_tank', 'counterparty_cuaa', 'quantity_kg', 'quantity_l', 'category', 'origin',
        'bottle_format', 'bottle_count', 'package_lot', 'best_before',
    ];

    /**
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function xml(string $companyCuaa, array $rows): string
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('RegistroTelematicoOlio');
        $writer->writeAttribute('cuaa', $companyCuaa);
        $writer->writeAttribute('operazioni', (string) count($rows));
        $writer->writeAttribute('totale_kg', number_format(array_sum(array_map(static fn (array $r): float => (float) ($r['quantity_kg'] ?? 0), $rows)), 3, '.', ''));

        foreach ($rows as $row) {
            $writer->startElement('Operazione');
            $writer->writeAttribute('progressivo', (string) $row['progressive']);

            foreach (array_slice(self::COLUMNS, 1) as $column) {
                $value = $row[$column] ?? null;

                if ($value !== null && $value !== '') {
                    $writer->writeElement($column, (string) $value);
                }
            }

            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function csv(array $rows): string
    {
        $lines = [implode(';', self::COLUMNS)];

        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(fn (string $column): string => $this->cell($row[$column] ?? null), self::COLUMNS));
        }

        return implode("\n", $lines)."\n";
    }

    private function cell(string|int|float|bool|null $value): string
    {
        $text = (string) $value;
        // A cell that starts like a formula is neutralised so a spreadsheet does not run it.
        $text = in_array(substr($text, 0, 1), ['=', '+', '-', '@'], true) && ! is_numeric($text) ? "'".$text : $text;

        return str_contains($text, ';') || str_contains($text, '"') || str_contains($text, "\n") ? '"'.str_replace('"', '""', $text).'"' : $text;
    }
}
