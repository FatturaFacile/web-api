<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Plm\Enums\RevisionLifecycleState;

/**
 * ProductEngineeringItemRevision: a revision of a manufacturing item with lifecycle state machine,
 * parent lineage, eBOM/mBOM/routing linkage, CAD drawings (PDF/DXF/STEP/STL), and CE/RoHS/REACH metadata.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mfg_item_id
 * @property string $revision
 * @property string $status
 * @property string|null $parent_revision_id
 * @property string|null $engineering_part_number
 * @property string|null $description
 * @property string $lifecycle_state
 * @property array<string, mixed>|null $ebom_structure_json
 * @property string|null $mbom_id
 * @property string|null $routing_id
 * @property list<array<string, mixed>>|null $cad_drawing_pdf_and_step_file_ids
 * @property bool $rohs_and_reach_compliant
 * @property bool $conflict_minerals_compliant
 * @property string|null $technical_file_ref
 * @property array<string, mixed>|null $regulatory_metadata_json
 * @property bool $mes_drawing_obsolete_watermark
 * @property string|null $superseded_by_revision_id
 * @property list<array<string, mixed>>|null $audit_trail_json
 * @property string|null $change_summary
 * @property string|null $change_order_id
 * @property Carbon|null $effective_from
 * @property Carbon|null $released_at
 * @property Carbon|null $obsoleted_at
 */
final class PlmItemRevision extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Released = 'released';

    public const string Obsolete = 'obsolete';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::Released, self::Obsolete];

    protected $table = 'plm_item_revisions';

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'released_at' => 'datetime',
            'obsoleted_at' => 'datetime',
            'ebom_structure_json' => 'array',
            'cad_drawing_pdf_and_step_file_ids' => 'array',
            'rohs_and_reach_compliant' => 'boolean',
            'conflict_minerals_compliant' => 'boolean',
            'regulatory_metadata_json' => 'array',
            'mes_drawing_obsolete_watermark' => 'boolean',
            'audit_trail_json' => 'array',
        ];
    }

    public function lifecycleStateEnum(): RevisionLifecycleState
    {
        $state = $this->getAttribute('lifecycle_state');
        if (is_string($state) && $state !== '') {
            $parsed = RevisionLifecycleState::tryFrom($state);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return RevisionLifecycleState::fromLegacyStatus((string) $this->status);
    }

    public function isObsoleteForMes(): bool
    {
        return (bool) $this->mes_drawing_obsolete_watermark
            || $this->status === self::Obsolete
            || $this->lifecycleStateEnum() === RevisionLifecycleState::ObsoleteBlocked;
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    /**
     * @return BelongsTo<PlmChangeOrder, $this>
     */
    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(PlmChangeOrder::class, 'change_order_id');
    }

    /**
     * @return BelongsTo<PlmItemRevision, $this>
     */
    public function parentRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    /**
     * @return HasMany<PlmItemRevision, $this>
     */
    public function childRevisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_revision_id');
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function mbom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'mbom_id');
    }

    /**
     * @return BelongsTo<MfgRouting, $this>
     */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'routing_id');
    }

    /**
     * @return HasOne<PlmTechnicalDossier, $this>
     */
    public function technicalDossier(): HasOne
    {
        return $this->hasOne(PlmTechnicalDossier::class, 'revision_id');
    }
}
