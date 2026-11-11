<?php

declare(strict_types=1);

namespace Modules\Integrations\OpenApi;

/**
 * Texts of the OpenAPI document that are shared by several operations.
 */
final class WebhookDocumentation
{
    /**
     * How a receiver verifies a delivery. Reference implementation: WebhookSigner::verify().
     */
    public const string SignatureRecipe = 'VERIFYING A DELIVERY. Every delivery carries X-FF-Webhook-Signature: t=<unix timestamp>,v1=<hex>[,v1=<hex>]. '
        .'1) Read the RAW request body (the exact bytes, before any JSON parsing). '
        .'2) Build the signed string: the t value, a dot, the raw body ("<t>.<body>"). '
        .'3) Compute HMAC-SHA256 of that string with the signing secret of the endpoint (the whole secret, "whsec_" prefix included) and hex-encode it. '
        .'4) Compare it, in constant time, with every v1 value of the header: the delivery is authentic when ANY matches (during a secret rotation the header carries one v1 per valid secret). '
        .'5) Reject the delivery when |now - t| is greater than 300 seconds (replay protection). '
        .'6) Deduplicate on the X-FF-Webhook-Id header (idempotency key): it is the same on every retry and manual redelivery of a delivery. '
        .'Example (PHP): $ok = hash_equals(hash_hmac("sha256", $t.".".$rawBody, $secret), $v1) && abs(time() - $t) <= 300; '
        .'Example (Node): crypto.timingSafeEqual(Buffer.from(crypto.createHmac("sha256", secret).update(t + "." + rawBody).digest("hex")), Buffer.from(v1)).';

    public const string RetryPolicy = 'RETRIES. A delivery succeeds on any 2xx answer within 10 seconds. Any other outcome (no answer, timeout, 4xx, 5xx, a redirect: redirects are never followed) is retried with exponential backoff (30 s, 1 min, 2 min, ... capped at 6 hours) up to 8 attempts; then the delivery is a dead letter (status "dead"), kept in the log and redeliverable by hand. '
        .'After 20 consecutive failed attempts without a success the endpoint is disabled and the owners and admins of the tenant are notified. Deliveries are rate limited per tenant (300 a minute): a delivery over the limit is postponed, not lost.';
}
