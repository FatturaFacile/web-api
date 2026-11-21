<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Support\Money;

/**
 * The structured difference between two BOM revisions: components added, removed and modified (quantity, scrap, yield,
 * fixed waste, phantom flag, issue method, operation, sequence). Stored on the engineering change as its diff payload
 * and shown side by side by the revision viewer.
 */
final class BomDiffService
{
    /** Compared line fields: field => decimal places (null for non-numeric fields). */
    private const array Fields = [
        'quantity_per' => 4,
        'scrap_percentage' => 2,
        'yield_percentage' => 2,
        'fixed_scrap_quantity' => 4,
        'is_phantom' => null,
        'issue_method' => null,
        'operation_sequence' => null,
        'sequence' => null,
    ];

    /**
     * Difference from one revision to another.
     *
     * @return array{from_bom_id: string, to_bom_id: string, from_revision: string, to_revision: string, added: list<array<string, mixed>>, removed: list<array<string, mixed>>, modified: list<array<string, mixed>>, summary: array{added: int, removed: int, modified: int, unchanged: int}}
     */
    public function between(MfgBom $from, MfgBom $to): array
    {
        $before = $this->lines($from);
        $after = $this->lines($to);

        $added = [];
        $removed = [];
        $modified = [];
        $unchanged = 0;

        foreach ($after as $itemId => $line) {
            if (! isset($before[$itemId])) {
                $added[] = ['component_item_id' => $itemId, ...$line];

                continue;
            }

            $changes = [];
            foreach ($line as $field => $value) {
                if ($before[$itemId][$field] !== $value) {
                    $changes[$field] = ['from' => $before[$itemId][$field], 'to' => $value];
                }
            }
            if ($changes === []) {
                $unchanged++;
            } else {
                $modified[] = ['component_item_id' => $itemId, 'component_item_code' => $line['component_item_code'], 'changes' => $changes];
            }
        }

        foreach ($before as $itemId => $line) {
            if (! isset($after[$itemId])) {
                $removed[] = ['component_item_id' => $itemId, ...$line];
            }
        }

        return [
            'from_bom_id' => (string) $from->id,
            'to_bom_id' => (string) $to->id,
            'from_revision' => $from->revision,
            'to_revision' => $to->revision,
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
            'summary' => ['added' => count($added), 'removed' => count($removed), 'modified' => count($modified), 'unchanged' => $unchanged],
        ];
    }

    /**
     * The comparable lines of a revision keyed by component item.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lines(MfgBom $bom): array
    {
        $lines = [];
        foreach ($bom->components()->with('componentItem')->get() as $component) {
            /** @var MfgBomComponent $component */
            $line = ['component_item_code' => (string) $component->componentItem?->item_code];
            foreach (self::Fields as $field => $decimals) {
                $value = $component->getAttribute($field);
                $line[$field] = $decimals !== null ? Money::round($value, $decimals) : $value;
            }
            $lines[(string) $component->component_item_id] = $line;
        }

        return $lines;
    }
}
