<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Models;

use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * An immutable compliance alert: an outbound transfer that the egress guard blocked.
 *
 * @property string $id
 * @property string|null $destination_country_iso2
 * @property string $purpose
 * @property string $reason
 * @property Carbon $occurred_at
 */
final class CrossBorderBlockedAttempt extends Model
{
    use BelongsToTenant;

    protected $table = 'core_cross_border_blocked_attempts';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(static function (self $record): void {
            if (empty($record->id)) {
                $record->id = (string) Str::uuid7();
            }
        });
        self::updating(static fn () => throw new LogicException('CrossBorderBlockedAttempt records are append-only and cannot be updated.'));
        self::deleting(static fn () => throw new LogicException('CrossBorderBlockedAttempt records are append-only and cannot be deleted.'));
    }

    public function delete(): ?bool
    {
        throw new LogicException('CrossBorderBlockedAttempt records are append-only and cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new LogicException('CrossBorderBlockedAttempt records are append-only and cannot be deleted.');
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
