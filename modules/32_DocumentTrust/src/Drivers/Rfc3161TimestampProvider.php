<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Drivers;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundUrlGuard;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\DocumentTrust\Contracts\TrustedTimestampProvider;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\PreservationSetting;
use Modules\DocumentTrust\Support\Rfc3161Codec;
use Modules\DocumentTrust\Support\TimestampToken;
use phpseclib3\Math\BigInteger;
use Throwable;

/**
 * RFC 3161 client for the time-stamp authority (TSA) the tenant configured: an https URL and optional basic
 * authentication credentials, both read from the (encrypted) preservation settings. The call goes through
 * OutboundUrlGuard, so private and internal addresses are refused and redirects are not followed.
 *
 * The response is validated by {@see Rfc3161Codec} (status, imprint, nonce, generation time, token signature). The
 * certificate chain is NOT validated, and `timestamp_qualified` only reflects the tenant's declaration that the TSA is a
 * qualified provider: it is never assumed. This client has been tested against locally generated responses only, not
 * against a live TSA.
 */
final readonly class Rfc3161TimestampProvider implements TrustedTimestampProvider
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext, private OutboundUrlGuard $guard) {}

    /**
     * The provider name the tenant gave, or `rfc3161`.
     */
    public function name(): string
    {
        return $this->settings()?->tsa_provider_name ?: 'rfc3161';
    }

    /**
     * Ask the authority to stamp the digest and validate its answer.
     */
    public function timestamp(string $sha256Hex): TimestampToken
    {
        $settings = $this->settings();
        $url = (string) $settings?->tsa_url;

        if ($settings === null || $url === '') {
            throw new DocumentTrustException('No time-stamp authority is configured.');
        }

        try {
            $this->guard->inspectUrl($url, ['https']);
        } catch (UnsafeOutboundUrlException) {
            throw new DocumentTrustException('The time-stamp authority URL is not an allowed https address.');
        }

        $digest = (string) hex2bin($sha256Hex);
        $nonce = new BigInteger(random_bytes(8), 256);
        $request = Http::timeout(20)->withBody(Rfc3161Codec::buildRequest($digest, $nonce), 'application/timestamp-query')->accept('application/timestamp-reply');

        if ($settings->tsa_username !== null && $settings->tsa_username !== '') {
            $request = $request->withBasicAuth((string) $settings->tsa_username, (string) $settings->tsa_password);
        }

        try {
            $response = $this->guard->protect($request)->post($url)->throw();
        } catch (ConnectionException|RequestException|UnsafeOutboundUrlException $exception) {
            throw new DocumentTrustException('The time-stamp authority could not be reached or refused the request.', 0, $exception);
        } catch (Throwable $exception) {
            throw new DocumentTrustException('The time-stamp authority could not be reached.', 0, $exception);
        }

        return Rfc3161Codec::parseResponse($response->body(), $digest, $nonce, $this->name(), (bool) $settings->tsa_declared_qualified);
    }

    private function settings(): ?PreservationSetting
    {
        return PreservationSetting::where('tenant_id', $this->tenantContext->getTenantId())->first();
    }
}
