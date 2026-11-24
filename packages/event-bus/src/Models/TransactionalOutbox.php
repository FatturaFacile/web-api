<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Models;

use Illuminate\Database\Eloquent\Model;

final class TransactionalOutbox extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PROCESSING = 'processing';

    /**
     * Written by the relay once the event has been dispatched.
     */
    public const string STATUS_COMPLETED = 'completed';

    /**
     * Older spelling of a dispatched event, still found on existing rows.
     */
    public const string STATUS_PROCESSED = 'processed';

    public const string STATUS_FAILED = 'failed';

    /**
     * The statuses of an event that has been dispatched and can be archived.
     *
     * @var list<string>
     */
    public const array DISPATCHED_STATUSES = [self::STATUS_COMPLETED, self::STATUS_PROCESSED];

    protected $table = 'transactional_outbox';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'retry_after' => 'datetime',
            'payload_json' => 'array',
            'retry_count' => 'integer',
        ];
    }
}
