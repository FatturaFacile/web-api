<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStatusTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'project_status_translations';

    protected $fillable = [
        'tenant_id',
        'project_status_id',
        'locale',
        'label',
        'description',
    ];

    /**
     * @return BelongsTo<ProjectStatus, $this>
     */
    public function projectStatus(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'project_status_id');
    }
}
