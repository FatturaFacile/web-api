<?php

declare(strict_types=1);

return [
    'idempotency' => [
        'title' => 'Idempotency key problem',
        'invalid_key' => 'The Idempotency-Key header must be a non-empty string of at most 255 characters.',
        'key_reused' => 'This Idempotency-Key was already used for a different request.',
        'in_progress' => 'A request with this Idempotency-Key is still being processed.',
    ],
    'conflict' => [
        'title' => 'Edit conflict',
        'detail' => 'The record was changed by someone else since you opened it. Reload it and apply your changes again.',
    ],
    'recycle_bin' => [
        'not_purgeable' => 'This kind of record must be kept for legal reasons and cannot be destroyed.',
        'in_use' => 'The record is still referenced by other records and cannot be destroyed.',
    ],
];
