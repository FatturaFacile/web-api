<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Support;

/**
 * The signing convention shared with the MES Core (`Mes.Infrastructure.LaravelSync.Hmac`): the header
 * `X-Mes-Signature: t=<unix>,kid=<key id>,v1=<hex>` where `v1 = HMAC-SHA256(secret[kid], t + "." + raw body)` in
 * lower-case hex. Requests without a body (GET) are signed over the empty string. Used to verify inbound calls and to
 * sign outbound ones, so both directions agree by construction.
 */
final class MesHmac
{
    public const string Header = 'X-Mes-Signature';

    /**
     * The lower-case hex HMAC of `t.body`.
     */
    public static function compute(string $secret, int|string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * The value of the signature header for a body.
     */
    public static function header(string $keyId, string $secret, string $body, int $timestamp): string
    {
        return sprintf('t=%d,kid=%s,v1=%s', $timestamp, $keyId, self::compute($secret, $timestamp, $body));
    }
}
