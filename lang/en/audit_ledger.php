<?php

declare(strict_types=1);

return [
    'export' => [
        'pdf' => [
            'title' => 'Audit ledger export - summary',
            'tenant' => 'Company',
            'period' => 'Period',
            'entries' => 'Entries',
            'sequences' => 'Sequences',
            'start_hash' => 'Hash of the first entry',
            'end_hash' => 'Hash of the last entry',
            'verification' => 'Chain verification',
            'generated' => 'Generated',
            'note' => 'The SHA-256 of this package is signed. Verify the chain with the hashes in manifest.json.',
        ],
    ],
    'alerts' => [
        'chain_broken' => [
            'title' => 'Audit trail integrity alert',
            'body' => 'The verification of the audit chain failed (:outcome, sequence :sequence). Open the audit settings and start the incident procedure.',
        ],
    ],
];
