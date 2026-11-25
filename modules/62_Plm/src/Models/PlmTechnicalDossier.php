<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Models\MfgItem;

/**
 * Technical Construction Dossier (Fascicolo Tecnico CE / UKCA / OMacch) for machinery & products.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mfg_item_id
 * @property string|null $revision_id
 * @property string $dossier_code
 * @property string $strategy_code
 * @property string $machinery_directive_ref
 * @property string $ce_declaration_status
 * @property string $ukca_declaration_status
 * @property bool $omacch_mra_ch_eu_compliant
 * @property bool $rohs_compliant
 * @property bool $reach_svhc_compliant
 * @property bool $conflict_minerals_compliant
 * @property list<string>|null $iso_audit_standards_json
 * @property list<array<string, mixed>>|null $checklist_items_json
 * @property array<string, mixed>|null $bom_compliance_rollup_json
 * @property Carbon|null $last_evaluated_at
 */
final class PlmTechnicalDossier extends BaseEnterpriseModel
{
    protected $table = 'plm_technical_dossiers';

    protected function casts(): array
    {
        return [
            'omacch_mra_ch_eu_compliant' => 'boolean',
            'rohs_compliant' => 'boolean',
            'reach_svhc_compliant' => 'boolean',
            'conflict_minerals_compliant' => 'boolean',
            'iso_audit_standards_json' => 'array',
            'checklist_items_json' => 'array',
            'bom_compliance_rollup_json' => 'array',
            'last_evaluated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    /**
     * @return BelongsTo<PlmItemRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(PlmItemRevision::class, 'revision_id');
    }
}
