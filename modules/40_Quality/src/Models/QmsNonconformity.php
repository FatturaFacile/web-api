<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A non-conformity of the quality management system: something that does not meet a requirement, from a supplier, a
 * customer complaint, an internal observation, an audit or manufacturing. It is investigated, handled with corrective
 * and preventive actions (CAPA) and closed. Related records are referenced by id only (contact, manufacturing
 * non-conformance, audit finding).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $title
 * @property string|null $description
 * @property string $source
 * @property string $severity
 * @property string $status
 * @property Carbon $detected_at
 * @property string|null $detected_by
 * @property string|null $owner_id
 * @property string|null $contact_id
 * @property string|null $mfg_non_conformance_id
 * @property string|null $audit_finding_id
 * @property string|null $containment_action
 * @property string|null $root_cause
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property string|null $closure_notes
 */
final class QmsNonconformity extends BaseEnterpriseModel
{
    public const array Sources = ['supplier', 'customer_complaint', 'internal', 'audit', 'manufacturing'];

    public const array Severities = ['minor', 'major', 'critical'];

    public const array OpenStatuses = ['open', 'investigating', 'capa_in_progress'];

    protected $table = 'qms_nonconformities';

    protected function casts(): array
    {
        return [
            'detected_at' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<QmsCapaAction, $this>
     */
    public function capaActions(): HasMany
    {
        return $this->hasMany(QmsCapaAction::class, 'nonconformity_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OpenStatuses, true);
    }
}
