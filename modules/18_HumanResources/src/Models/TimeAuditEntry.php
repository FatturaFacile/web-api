<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\TimeAuditEntryFactory;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;
use Modules\HumanResources\Casts\UtcInstant;

/**
 * An immutable audit record of a change to the working time records (who, when, what, why). Not a
 * BaseEnterpriseModel: an append-only row has no soft delete, and it would only be copied to the audit ledger.
 * The database refuses changes too (trigger on PostgreSQL); the only deletion is the retention purge, by query.
 */
#[UseFactory(TimeAuditEntryFactory::class)]
final class TimeAuditEntry extends Model
{
    use BelongsToTenant, HasUuidV7;

    /** @use HasFactory<TimeAuditEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const IMMUTABLE_MESSAGE = 'Time audit entries are immutable.';

    protected $table = 'time_audit_entries';

    protected $guarded = [];

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw new LogicException(self::IMMUTABLE_MESSAGE);
        };

        self::updating($refuse);
        self::deleting($refuse);
    }

    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'occurred_at' => UtcInstant::class,
            'created_at' => UtcInstant::class,
        ];
    }

    /**
     * Mass updates and deletes do not fire model events, so they are refused here.
     *
     * @param  QueryBuilder  $query
     * @return Builder<TimeAuditEntry>
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            /**
             * @param  array<string, mixed>  $values
             */
            public function update(array $values): int
            {
                throw new LogicException(TimeAuditEntry::IMMUTABLE_MESSAGE);
            }

            public function delete(): mixed
            {
                throw new LogicException(TimeAuditEntry::IMMUTABLE_MESSAGE);
            }

            public function forceDelete(): mixed
            {
                throw new LogicException(TimeAuditEntry::IMMUTABLE_MESSAGE);
            }
        };
    }
}
