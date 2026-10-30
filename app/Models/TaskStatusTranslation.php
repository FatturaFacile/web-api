<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskStatusTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'task_status_translations';

    protected $fillable = [
        'tenant_id',
        'task_status_id',
        'locale',
        'label',
        'description',
    ];

    /**
     * @return BelongsTo<TaskStatus, $this>
     */
    public function taskStatus(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'task_status_id');
    }
}
