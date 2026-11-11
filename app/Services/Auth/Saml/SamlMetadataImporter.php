<?php

declare(strict_types=1);

namespace App\Services\Auth\Saml;

use App\Services\Auth\Sso\SsoException;
use App\Services\Auth\Sso\SsoUrlGuard;
use ErrorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\Utils;
use Throwable;

/**
 * Reads the SAML metadata of an identity provider, from a URL or from pasted XML, into the fields stored on the tenant
 * provider: entity id, single sign-on URL, single logout URL and the signing certificates.
 *
 * The URL is fetched here with the HTTP client behind SsoUrlGuard (https, no private addresses, no redirects, size and
 * time limits), never by the library, and the XML is parsed by the library without network access.
 */
final class SamlMetadataImporter
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(private readonly SsoUrlGuard $guard) {}

    /**
     * @return array{idp_entity_id: string, idp_sso_url: string, idp_slo_url: string|null, idp_certificates: list<string>}
     *
     * @throws SsoException
     */
    public function fromUrl(string $url): array
    {
        $this->guard->assertSafe($url, 'metadata_url');

        try {
            $response = Http::timeout((int) config('sso.http_timeout_seconds'))->withoutRedirecting()->get($url);
        } catch (ConnectionException) {
            throw new SsoException('metadata_unreachable');
        }

        if (! $response->successful()) {
            throw new SsoException('metadata_failed', (string) $response->status());
        }

        return $this->fromXml($response->body());
    }

    /**
     * @return array{idp_entity_id: string, idp_sso_url: string, idp_slo_url: string|null, idp_certificates: list<string>}
     *
     * @throws SsoException
     */
    public function fromXml(string $xml): array
    {
        if (strlen($xml) > self::MAX_BYTES) {
            throw new SsoException('metadata_too_large');
        }

        try {
            $parsed = IdPMetadataParser::parseXML($xml)['idp'] ?? [];
        } catch (Throwable) {
            throw new SsoException('metadata_invalid');
        }

        $entityId = (string) ($parsed['entityId'] ?? '');
        $ssoUrl = (string) ($parsed['singleSignOnService']['url'] ?? '');
        $sloUrl = isset($parsed['singleLogoutService']['url']) ? (string) $parsed['singleLogoutService']['url'] : null;
        $certificates = array_values(array_unique(array_filter([
            ...(isset($parsed['x509cert']) ? [(string) $parsed['x509cert']] : []),
            ...array_map('strval', (array) ($parsed['x509certMulti']['signing'] ?? [])),
        ])));

        if ($entityId === '' || $ssoUrl === '' || $certificates === []) {
            throw new SsoException('metadata_incomplete');
        }

        $this->guard->assertSafe($ssoUrl, 'idp_sso_url');

        if ($sloUrl !== null) {
            $this->guard->assertSafe($sloUrl, 'idp_slo_url');
        }

        return [
            'idp_entity_id' => $entityId,
            'idp_sso_url' => $ssoUrl,
            'idp_slo_url' => $sloUrl,
            'idp_certificates' => $this->normalisedCertificates($certificates),
        ];
    }

    /**
     * The certificates as bare base64 bodies, each checked to be a parsable X.509 certificate.
     *
     * @param  list<string>  $certificates
     * @return list<string>
     *
     * @throws SsoException
     */
    public function normalisedCertificates(array $certificates): array
    {
        $bodies = [];

        foreach ($certificates as $certificate) {
            $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certificate);

            if (! is_string($body) || $body === '' || ! $this->isCertificate($body)) {
                throw new SsoException('certificate_invalid');
            }

            $bodies[] = $body;
        }

        return array_values(array_unique($bodies));
    }

    private function isCertificate(string $body): bool
    {
        try {
            return openssl_x509_read(Utils::formatCert($body)) !== false;
        } catch (ErrorException) {
            return false;
        }
    }
}
