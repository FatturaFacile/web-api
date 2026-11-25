<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;

/**
 * CadToErpImportSession: tracks CAD exports (SolidWorks, Inventor, Creo, NX, Eplan, Altium)
 * parsed into eBOM part lists, newly created items, side-by-side diffs vs mBOM, and commit status.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $session_code
 * @property string|null $product_id
 * @property string|null $target_revision_id
 * @property string|null $target_mbom_id
 * @property string $cad_source_system
 * @property array<string, mixed> $source_file_metadata
 * @property list<array<string, mixed>> $parsed_part_list_json
 * @property int $new_parts_to_create_count
 * @property list<array<string, mixed>>|null $created_parts_json
 * @property list<array<string, mixed>>|null $changed_quantities_diff_list
 * @property list<array<string, mixed>>|null $removed_parts_list
 * @property array<string, mixed>|null $side_by_side_diff_json
 * @property array<string, mixed>|null $cost_rollup_comparison_json
 * @property list<string>|null $validation_warnings_json
 * @property string $status
 * @property string|null $created_by
 * @property Carbon|null $committed_at
 */
final class PlmCadImportSession extends BaseEnterpriseModel
{
    protected $table = 'plm_cad_import_sessions';

    protected function casts(): array
    {
        return [
            'source_file_metadata' => 'array',
            'parsed_part_list_json' => 'array',
            'new_parts_to_create_count' => 'integer',
            'created_parts_json' => 'array',
            'changed_quantities_diff_list' => 'array',
            'removed_parts_list' => 'array',
            'side_by_side_diff_json' => 'array',
            'cost_rollup_comparison_json' => 'array',
            'validation_warnings_json' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'product_id');
    }

    /**
     * @return BelongsTo<PlmItemRevision, $this>
     */
    public function targetRevision(): BelongsTo
    {
        return $this->belongsTo(PlmItemRevision::class, 'target_revision_id');
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function targetMbom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'target_mbom_id');
    }
}
