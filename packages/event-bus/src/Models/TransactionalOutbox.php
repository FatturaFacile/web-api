<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Models;

use Illuminate\Database\Eloquent\Model;

final class TransactionalOutbox extends Model
{
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
