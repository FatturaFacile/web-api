<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Data residency, sovereignty and BYOK/HYOK (Mod 381)
|--------------------------------------------------------------------------
*/

return [
    // Base64 of the 32-byte Ed25519 seed that signs the sovereignty attestations. Production must set it; local runs derive one.
    'attestation_signing_key' => env('DATA_RESIDENCY_ATTESTATION_KEY'),

    // Validity of an issued attestation, in days.
    'attestation_validity_days' => (int) env('DATA_RESIDENCY_ATTESTATION_DAYS', 90),

    // The node name written in the KMS access log.
    'source_node_code' => env('DATA_RESIDENCY_SOURCE_NODE', null),

    // Host (or domain suffix) => ISO country, from the deployment's GeoIP/DNS region verification of its connectors.
    'host_countries' => [
        'fatturafacile.eu' => 'CH',
    ],

    // Typed phrase the kill-switch dialog requires (the API checks it too).
    'kill_switch_confirmation' => 'SUSPEND KEY ACCESS',
    'crypto_shred_confirmation' => 'PERMANENTLY SHRED',
];
