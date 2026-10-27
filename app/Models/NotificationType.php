<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationType extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'notification_types';

    protected $fillable = [
        'code',
        'channel',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<NotificationTypeTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(NotificationTypeTranslation::class, 'notification_type_id');
    }

    protected function getTranslationModelClass(): string
    {
        return NotificationTypeTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'notification_type_id';
    }
}
