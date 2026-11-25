<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\ActionStatus;

/**
 * Corrective action opened by a weak criterion of a store audit.
 *
 * @property string $audit_id
 * @property string $store_id
 * @property string $criterion_code
 * @property string $description
 * @property Carbon $due_date
 * @property ActionStatus $status
 * @property Carbon|null $completed_at
 * @property string|null $completion_note
 */
final class AuditAction extends BaseEnterpriseModel
{
    protected $table = 'frn_audit_actions';

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'status' => ActionStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StoreAudit, $this>
     */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(StoreAudit::class, 'audit_id');
    }

    /**
     * @return BelongsTo<FranchiseStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(FranchiseStore::class, 'store_id');
    }
}
