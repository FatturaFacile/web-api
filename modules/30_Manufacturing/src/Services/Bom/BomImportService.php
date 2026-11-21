<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;

/**
 * Dry-run validation of a BOM import (CAD, CSV or Excel rows already mapped to columns by the wizard): unknown item
 * codes, unit mismatches, bad quantities, duplicated lines, the product listed as its own component and circular
 * structures at any depth. Valid rows come back as component lines ready to be committed as a BOM draft.
 */
final readonly class BomImportService
{
    public function __construct(private BomCycleDetector $cycles) {}

    /**
     * Validates the rows against the items of the company.
     *
     * @param  list<array<string, mixed>>  $rows  item_code, quantity_per, and optionally uom, scrap_percentage, yield_percentage, fixed_scrap_quantity, operation_sequence, notes
     * @return array{valid: bool, product_item_id: string|null, rows: list<array<string, mixed>>, components: list<array<string, mixed>>, summary: array{total: int, valid: int, invalid: int}}
     */
    public function dryRun(string $tenantId, string $productCode, array $rows): array
    {
        $product = MfgItem::query()->where('tenant_id', $tenantId)->where('item_code', $productCode)->first();
        $codes = array_values(array_unique(array_filter(array_map(static fn (array $row): string => (string) ($row['item_code'] ?? ''), $rows))));
        /** @var array<string, MfgItem> $items */
        $items = $codes === [] ? [] : MfgItem::query()->where('tenant_id', $tenantId)->whereIn('item_code', $codes)->get()->keyBy('item_code')->all();

        $report = [];
        $components = [];
        $seen = [];
        $productProblem = $product instanceof MfgItem ? null : 'unknown_product';

        foreach ($rows as $index => $row) {
            $code = (string) ($row['item_code'] ?? '');
            $item = $items[$code] ?? null;
            $errors = [];

            if ($code === '' || ! $item instanceof MfgItem) {
                $errors[] = 'unknown_item';
            }
            if (! is_numeric($row['quantity_per'] ?? null) || ! Money::isPositive((string) $row['quantity_per'])) {
                $errors[] = 'invalid_quantity';
            }
            if ($item instanceof MfgItem) {
                if (isset($row['uom']) && $row['uom'] !== '' && strcasecmp((string) $row['uom'], $item->uom) !== 0) {
                    $errors[] = 'uom_mismatch';
                }
                if (isset($seen[(string) $item->id])) {
                    $errors[] = 'duplicate_item';
                }
                $seen[(string) $item->id] = true;
                if ($product instanceof MfgItem && (string) $item->id === (string) $product->id) {
                    $errors[] = 'self_reference';
                }
            }

            $report[] = ['line' => $index + 1, 'item_code' => $code, 'status' => $errors === [] ? 'ok' : 'error', 'errors' => $errors];

            if ($errors === [] && $item instanceof MfgItem) {
                $components[] = [
                    'component_item_id' => (string) $item->id,
                    'quantity_per' => Money::qty((string) $row['quantity_per']),
                    'scrap_percentage' => Money::round($row['scrap_percentage'] ?? '0'),
                    'yield_percentage' => Money::round($row['yield_percentage'] ?? '100'),
                    'fixed_scrap_quantity' => Money::qty($row['fixed_scrap_quantity'] ?? '0'),
                    'operation_sequence' => isset($row['operation_sequence']) ? (int) $row['operation_sequence'] : null,
                    'notes' => isset($row['notes']) ? (string) $row['notes'] : null,
                ];
            }
        }

        // Circular structures: the valid lines are checked together against the existing structure of the company.
        if ($product instanceof MfgItem && $components !== []) {
            $cycle = $this->cycles->findCycle($tenantId, (string) $product->id, array_map(static fn (array $component): string => (string) $component['component_item_id'], $components), $this->liveBomIdOf($tenantId, (string) $product->id));
            if ($cycle !== null) {
                $culprit = $cycle[1] ?? null;
                foreach ($report as $position => $entry) {
                    $item = $items[$entry['item_code']] ?? null;
                    if ($item instanceof MfgItem && (string) $item->id === $culprit && $entry['status'] === 'ok') {
                        $report[$position]['status'] = 'error';
                        $report[$position]['errors'][] = 'circular_reference';
                    }
                }
                $components = array_values(array_filter($components, static fn (array $component): bool => (string) $component['component_item_id'] !== $culprit));
            }
        }

        $invalid = count(array_filter($report, static fn (array $entry): bool => $entry['status'] === 'error'));

        return [
            'valid' => $productProblem === null && $invalid === 0 && $rows !== [],
            'product_item_id' => $product instanceof MfgItem ? (string) $product->id : null,
            'rows' => $report,
            'components' => $components,
            'summary' => ['total' => count($rows), 'valid' => count($rows) - $invalid, 'invalid' => $invalid],
        ];
    }

    /**
     * The newest draft of the product, whose stored lines the import would replace.
     */
    private function liveBomIdOf(string $tenantId, string $itemId): ?string
    {
        $id = MfgBom::query()->where('tenant_id', $tenantId)->where('mfg_item_id', $itemId)->where('status', 'draft')->orderByDesc('version')->value('id');

        return $id !== null ? (string) $id : null;
    }
}
