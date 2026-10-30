<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTypeTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'notification_type_translations';

    protected $fillable = [
        'tenant_id',
        'notification_type_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<NotificationType, $this>
     */
    public function notificationType(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }
}
