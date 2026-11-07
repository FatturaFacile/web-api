<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Associations\Enums\MinutesStatus;

/**
 * The minutes of a meeting. Editable as a draft; once approved a database trigger refuses any change or deletion. The
 * text names people, so it is stored encrypted.
 *
 * @property string $id
 * @property string $meeting_id
 * @property MinutesStatus $status
 * @property string $title
 * @property string $body
 * @property ?string $number
 * @property-read Meeting $meeting
 */
final class Minutes extends AssociationRecord
{
    protected $table = 'assoc_minutes';

    protected static function booted(): void
    {
        $refuse = static function (self $minutes): void {
            if ($minutes->getOriginal('status') === MinutesStatus::Approved) {
                throw new LogicException('Approved minutes cannot be changed or deleted.');
            }
        };

        self::updating($refuse);
        self::deleting($refuse);
    }

    protected function casts(): array
    {
        return [
            'status' => MinutesStatus::class,
            'body' => 'encrypted',
            'approved_at' => 'datetime',
            'fiscal_year' => 'integer',
            'sequential_number' => 'integer',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }
}
