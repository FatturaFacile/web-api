<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class WorkflowAuditEntry extends BaseEnterpriseModel
{
    protected $table = 'workflow_audit_entries';

    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        self::updating(function (): void {
            throw new LogicException('Workflow audit entries are strictly immutable and cannot be updated.');
        });

        self::deleting(function (): void {
            throw new LogicException('Workflow audit entries are strictly immutable and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'event_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstanceStep::class, 'workflow_instance_step_id');
    }
}
