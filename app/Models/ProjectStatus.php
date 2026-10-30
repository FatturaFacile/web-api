<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectStatus extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, HasUuids;

    protected $table = 'project_statuses';

    protected $fillable = [
        'tenant_id',
        'code',
        'color_badge',
        'sort_order',
        'is_terminal',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_terminal' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ProjectStatusTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProjectStatusTranslation::class, 'project_status_id');
    }

    protected function getTranslationModelClass(): string
    {
        return ProjectStatusTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'project_status_id';
    }
}
