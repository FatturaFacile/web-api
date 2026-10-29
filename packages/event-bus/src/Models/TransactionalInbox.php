<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Models;

use Illuminate\Database\Eloquent\Model;

final class TransactionalInbox extends Model
{
    protected $table = 'transactional_inbox';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'payload_json' => 'array',
        ];
    }
}
