<?php

declare(strict_types=1);

return [
    'messages' => [
        'state' => 'The state was reconstructed at the requested coordinate.',
        'diff' => 'The cut-off difference was calculated.',
        'whatif' => 'The what-if valuation replay was completed.',
        'integrity_verified' => 'Integrity verified: no silent tampering found.',
        'integrity_broken' => 'Integrity check failed: the audit chain was altered.',
        'checkpoints' => 'The checkpoints were materialized.',
    ],
    'errors' => [
        'range_too_long' => 'The range cannot exceed :days days.',
    ],
];
