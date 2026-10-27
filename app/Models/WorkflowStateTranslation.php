<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStateTranslation extends Model
{
    use HasFactory;

    protected $table = 'workflow_state_translations';

    protected $fillable = [
        'workflow_state_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<WorkflowState, $this>
     */
    public function workflowState(): BelongsTo
    {
        return $this->belongsTo(WorkflowState::class, 'workflow_state_id');
    }
}
