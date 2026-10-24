<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One backup archive of a tenant: its state, and where the ZIP is stored once completed.
 */
class TenantBackup extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'filename',
        'disk',
        'file_size_bytes',
        'status',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Location of the archive on its disk.
     */
    public function storagePath(): string
    {
        return "backups/{$this->tenant_id}/{$this->filename}";
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
