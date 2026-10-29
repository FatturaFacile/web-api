<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowState extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'workflow_states';

    protected $fillable = [
        'scope',
        'code',
        'is_initial',
        'is_final',
    ];

    protected function casts(): array
    {
        return [
            'is_initial' => 'boolean',
            'is_final' => 'boolean',
        ];
    }

    /**
     * @return HasMany<WorkflowStateTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(WorkflowStateTranslation::class, 'workflow_state_id');
    }

    protected function getTranslationModelClass(): string
    {
        return WorkflowStateTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'workflow_state_id';
    }
}
