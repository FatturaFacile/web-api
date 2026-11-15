<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Documents\Enums\ApprovalStatus;

/**
 * A document sent through an approval template. The number of steps is fixed at request time and the steps are read
 * from the template, which cannot change while a request is pending.
 *
 * @property string $id
 * @property string $document_id
 * @property ?string $document_version_id
 * @property ?string $template_id
 * @property string $template_name
 * @property ApprovalStatus $status
 * @property int $current_step
 * @property int $total_steps
 * @property ?string $requested_by
 * @property ?string $note
 * @property ?Carbon $completed_at
 */
final class DmsApprovalRequest extends BaseEnterpriseModel
{
    protected $table = 'dms_approval_requests';

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'current_step' => 'integer',
            'total_steps' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DmsDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(DmsDocument::class, 'document_id');
    }

    /**
     * @return BelongsTo<DmsApprovalTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DmsApprovalTemplate::class, 'template_id');
    }

    /**
     * @return HasMany<DmsApprovalDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(DmsApprovalDecision::class, 'request_id')->orderBy('decided_at')->orderBy('step_position');
    }
}
