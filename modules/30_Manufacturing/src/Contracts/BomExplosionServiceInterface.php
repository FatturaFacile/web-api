<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\Models\MfgBom;

/**
 * Integration contract of the BOM explosion for production order generation (Mod 064): scalar requirements of a target
 * quantity on a date, with waste accumulated across levels and phantom assemblies flattened away.
 *
 * Row shape: level, component_item_id, component_item_code, component_name, parent_item_id, net_quantity,
 * variable_waste_quantity, fixed_waste_quantity, scrap_quantity, effective_quantity (gross), uom, is_phantom,
 * via_phantom, has_bom, issue_method, operation_sequence, item_lead_time_days, lead_time_offset_days, path.
 */
interface BomExplosionServiceInterface
{
    /**
     * Explodes a revision for a quantity on a date.
     *
     * @param  bool  $multiLevel  false lists the first level only
     * @param  bool  $flattenPhantoms  true replaces phantom assemblies by the components they consist of
     * @return array{bom_id: string, quantity: string, date: string, multi_level: bool, flatten_phantoms: bool, total_lead_time_days: int, lines: list<array<string, mixed>>}
     */
    public function explode(MfgBom $bom, string $quantity, ?string $date = null, bool $multiLevel = true, bool $flattenPhantoms = true): array;
}
