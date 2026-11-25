<?php

declare(strict_types=1);

namespace Modules\Plm\Services;

use Illuminate\Validation\ValidationException;

/**
 * Parses CAD BOM exports from XML, CSV, or STEP (ISO 10303-21 PRODUCT/NEXT_ASSEMBLY_USAGE_OCCURRENCE)
 * into a normalized part list for eBOM -> mBOM synchronization.
 */
final class CadPayloadParser
{
    /**
     * @param  list<array<string, mixed>>|null  $structuredParts
     * @return list<array{
     *     item_code: string,
     *     name: string,
     *     quantity: string,
     *     uom: string,
     *     unit_cost: string,
     *     level: int,
     *     parent_code: string|null,
     *     drawing_number: string|null,
     *     rohs_compliant: bool,
     *     reach_compliant: bool,
     *     conflict_minerals_compliant: bool
     * }>
     */
    public function parse(?string $rawPayload, ?string $format, ?array $structuredParts = null): array
    {
        if (is_array($structuredParts) && $structuredParts !== []) {
            return $this->normalizeRows($structuredParts);
        }

        $payload = trim((string) $rawPayload);
        if ($payload === '') {
            throw ValidationException::withMessages(['parts' => [__('plm_errors.cad.empty_payload')]]);
        }

        $detected = strtolower(trim((string) $format));
        if ($detected === '') {
            $detected = match (true) {
                str_starts_with($payload, '<') => 'xml',
                str_contains($payload, 'ISO-10303-21') || str_contains($payload, 'NEXT_ASSEMBLY_USAGE_OCCURRENCE') => 'step',
                default => 'csv',
            };
        }

        return match ($detected) {
            'xml' => $this->parseXml($payload),
            'step', 'stp' => $this->parseStep($payload),
            default => $this->parseCsv($payload),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{item_code: string, name: string, quantity: string, uom: string, unit_cost: string, level: int, parent_code: string|null, drawing_number: string|null, rohs_compliant: bool, reach_compliant: bool, conflict_minerals_compliant: bool}>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['item_code'] ?? $row['part_number'] ?? $row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $normalized[] = [
                'item_code' => $code,
                'name' => trim((string) ($row['name'] ?? $row['description'] ?? $code)),
                'quantity' => $this->decimalString($row['quantity'] ?? $row['qty'] ?? '1'),
                'uom' => strtoupper(trim((string) ($row['uom'] ?? 'PCS'))),
                'unit_cost' => $this->decimalString($row['unit_cost'] ?? $row['cost'] ?? '0'),
                'level' => max(1, (int) ($row['level'] ?? 1)),
                'parent_code' => isset($row['parent_code']) && trim((string) $row['parent_code']) !== '' ? trim((string) $row['parent_code']) : null,
                'drawing_number' => isset($row['drawing_number']) && trim((string) $row['drawing_number']) !== '' ? trim((string) $row['drawing_number']) : null,
                'rohs_compliant' => (bool) ($row['rohs_compliant'] ?? true),
                'reach_compliant' => (bool) ($row['reach_compliant'] ?? true),
                'conflict_minerals_compliant' => (bool) ($row['conflict_minerals_compliant'] ?? true),
            ];
        }

        if ($normalized === []) {
            throw ValidationException::withMessages(['parts' => [__('plm_errors.cad.no_valid_parts')]]);
        }

        return $normalized;
    }

    /**
     * @return list<array{item_code: string, name: string, quantity: string, uom: string, unit_cost: string, level: int, parent_code: string|null, drawing_number: string|null, rohs_compliant: bool, reach_compliant: bool, conflict_minerals_compliant: bool}>
     */
    private function parseXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            throw ValidationException::withMessages(['raw_payload' => [__('plm_errors.cad.invalid_xml')]]);
        }

        $rows = [];
        $nodes = $doc->xpath('//part | //component | //item | //bom_line') ?: [];
        foreach ($nodes as $node) {
            $attrs = $node->attributes();
            $rows[] = [
                'item_code' => (string) ($node->item_code ?? $node->part_number ?? $node->code ?? ($attrs !== null ? ($attrs['code'] ?? $attrs['part_number'] ?? '') : '')),
                'name' => (string) ($node->name ?? $node->description ?? ($attrs !== null ? ($attrs['name'] ?? '') : '')),
                'quantity' => (string) ($node->quantity ?? $node->qty ?? ($attrs !== null ? ($attrs['quantity'] ?? '1') : '1')),
                'uom' => (string) ($node->uom ?? ($attrs !== null ? ($attrs['uom'] ?? 'PCS') : 'PCS')),
                'unit_cost' => (string) ($node->unit_cost ?? $node->cost ?? ($attrs !== null ? ($attrs['unit_cost'] ?? '0') : '0')),
                'level' => (int) ($node->level ?? ($attrs !== null ? ($attrs['level'] ?? 1) : 1)),
                'parent_code' => (string) ($node->parent_code ?? ($attrs !== null ? ($attrs['parent_code'] ?? '') : '')),
                'drawing_number' => (string) ($node->drawing_number ?? ($attrs !== null ? ($attrs['drawing_number'] ?? '') : '')),
                'rohs_compliant' => filter_var((string) ($node->rohs_compliant ?? 'true'), FILTER_VALIDATE_BOOLEAN),
                'reach_compliant' => filter_var((string) ($node->reach_compliant ?? 'true'), FILTER_VALIDATE_BOOLEAN),
                'conflict_minerals_compliant' => filter_var((string) ($node->conflict_minerals_compliant ?? 'true'), FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $this->normalizeRows($rows);
    }

    /**
     * @return list<array{item_code: string, name: string, quantity: string, uom: string, unit_cost: string, level: int, parent_code: string|null, drawing_number: string|null, rohs_compliant: bool, reach_compliant: bool, conflict_minerals_compliant: bool}>
     */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
        if ($lines === []) {
            throw ValidationException::withMessages(['raw_payload' => [__('plm_errors.cad.empty_payload')]]);
        }

        $delimiter = str_contains($lines[0], ';') ? ';' : ',';
        $headers = array_map(static fn (?string $h): string => strtolower(trim((string) $h)), str_getcsv($lines[0], $delimiter));
        $rows = [];

        foreach (array_slice($lines, 1) as $line) {
            $values = str_getcsv($line, $delimiter);
            $assoc = [];
            foreach ($headers as $idx => $header) {
                if ($header !== '') {
                    $assoc[$header] = $values[$idx] ?? '';
                }
            }
            $rows[] = $assoc;
        }

        return $this->normalizeRows($rows);
    }

    /**
     * @return list<array{item_code: string, name: string, quantity: string, uom: string, unit_cost: string, level: int, parent_code: string|null, drawing_number: string|null, rohs_compliant: bool, reach_compliant: bool, conflict_minerals_compliant: bool}>
     */
    private function parseStep(string $step): array
    {
        $products = [];
        if (preg_match_all("/#(\d+)\s*=\s*PRODUCT\s*\(\s*'([^']+)'\s*,\s*'([^']*)'/i", $step, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $products[$match[1]] = [
                    'item_code' => trim($match[2]),
                    'name' => trim($match[3]) !== '' ? trim($match[3]) : trim($match[2]),
                    'quantity' => '1',
                    'uom' => 'PCS',
                    'unit_cost' => '0',
                    'level' => 1,
                    'parent_code' => null,
                    'drawing_number' => 'DWG-'.trim($match[2]),
                    'rohs_compliant' => true,
                    'reach_compliant' => true,
                    'conflict_minerals_compliant' => true,
                ];
            }
        }

        if ($products === []) {
            throw ValidationException::withMessages(['raw_payload' => [__('plm_errors.cad.no_valid_parts')]]);
        }

        return $this->normalizeRows(array_values($products));
    }

    private function decimalString(mixed $value): string
    {
        $numeric = is_numeric($value) ? (float) $value : 1.0;

        return number_format(max(0.0, $numeric), 4, '.', '');
    }
}
