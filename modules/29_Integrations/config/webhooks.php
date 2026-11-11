<?php

declare(strict_types=1);

return [
    /*
    | Payload versions a receiver can pin on its endpoint. A new version is added here and handled in
    | WebhookPayloadBuilder; an endpoint keeps the version it was created with until its owner changes it.
    */
    'payload_versions' => ['v1'],
    'default_payload_version' => 'v1',

    /*
    | Retries: the n-th failed attempt of a delivery schedules the next one after
    | min(backoff_base_seconds * 2^(n - 1), backoff_max_seconds) seconds, with up to 10% of jitter. After max_attempts
    | failed attempts the delivery is a dead letter: it stays in the log and can be redelivered by hand.
    */
    'max_attempts' => 8,
    'backoff_base_seconds' => 30,
    'backoff_max_seconds' => 21600,

    /*
    | An endpoint is disabled (and the tenant owners and admins notified) after this many consecutive failed attempts
    | without a success in between. Test pings do not count.
    */
    'auto_disable_after_failures' => 20,

    /*
    | Deliveries sent per tenant in a window. A delivery over the limit is not lost nor counted as a failed
    | attempt: it is postponed to the next window.
    */
    'rate_limit_per_minute' => 300,

    'max_endpoints_per_tenant' => 20,

    'connect_timeout_seconds' => 5,
    'timeout_seconds' => 10,
    'response_excerpt_bytes' => 1024,

    /*
    | Hours the previous signing secret is still accepted after a rotation (the payload is signed with both).
    */
    'default_secret_overlap_hours' => 24,
    'max_secret_overlap_hours' => 168,

    /*
    | A signature older than this many seconds should be rejected by the receiver (replay protection). Documented
    | in the OpenAPI recipe, not enforced on our side.
    */
    'signature_tolerance_seconds' => 300,

    /*
    | A delivery claimed by a worker that died is picked up again after this many seconds.
    */
    'claim_timeout_seconds' => 120,

    /*
    | Delivery rows older than this many days (succeeded or dead) are deleted by webhooks:prune.
    */
    'retention_days' => 90,
];
