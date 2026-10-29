<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskStatus extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'task_statuses';

    protected $fillable = [
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
     * @return HasMany<TaskStatusTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(TaskStatusTranslation::class, 'task_status_id');
    }

    protected function getTranslationModelClass(): string
    {
        return TaskStatusTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'task_status_id';
    }
}
