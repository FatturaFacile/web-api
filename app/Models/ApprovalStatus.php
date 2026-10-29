<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalStatus extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'approval_statuses';

    protected $fillable = [
        'code',
        'color_badge',
    ];

    /**
     * @return HasMany<ApprovalStatusTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ApprovalStatusTranslation::class, 'approval_status_id');
    }

    protected function getTranslationModelClass(): string
    {
        return ApprovalStatusTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'approval_status_id';
    }
}
