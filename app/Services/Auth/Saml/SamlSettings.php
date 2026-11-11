<?php

declare(strict_types=1);

namespace App\Services\Auth\Saml;

use App\Models\SamlProvider;
use OneLogin\Saml2\Constants;

/**
 * The settings array of onelogin/php-saml for the SAML provider of one tenant, always in strict mode.
 *
 * The service provider is per tenant: its entity id, its assertion consumer service and its single logout service are
 * URLs that contain the tenant id, so an assertion issued for one tenant (its Audience and Destination) is not valid for
 * another one, even when both use the same identity provider.
 *
 * Security choices: strict mode on; the assertion must be signed (`wantAssertionsSigned`); the XML is validated against
 * the SAML schemas; the Destination must match the ACS URL exactly; a response that carries an InResponseTo is refused
 * when no request is awaited; request signing is on only when the tenant stored a service provider key and certificate.
 * Library: onelogin/php-saml (SAML-Toolkits), which does the XML signature, audience, time condition and
 * SubjectConfirmation checks.
 */
final class SamlSettings
{
    public static function entityId(string $tenantId): string
    {
        return route('auth.saml.metadata', ['tenantId' => $tenantId]);
    }

    public static function acsUrl(string $tenantId): string
    {
        return route('auth.saml.acs', ['tenantId' => $tenantId]);
    }

    public static function sloUrl(string $tenantId): string
    {
        return route('auth.saml.slo', ['tenantId' => $tenantId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(SamlProvider $provider): array
    {
        $tenantId = (string) $provider->tenant_id;
        $certificates = array_map('strval', $provider->idp_certificates);
        $signsRequests = $provider->sp_private_key !== null && $provider->sp_private_key !== '' && $provider->sp_certificate !== null && $provider->sp_certificate !== '';

        $idp = [
            'entityId' => $provider->idp_entity_id,
            'singleSignOnService' => ['url' => $provider->idp_sso_url, 'binding' => Constants::BINDING_HTTP_REDIRECT],
        ];

        if ($provider->idp_slo_url !== null && $provider->idp_slo_url !== '') {
            $idp['singleLogoutService'] = ['url' => $provider->idp_slo_url, 'binding' => Constants::BINDING_HTTP_REDIRECT];
        }

        if (count($certificates) === 1) {
            $idp['x509cert'] = $certificates[0];
        } else {
            $idp['x509certMulti'] = ['signing' => $certificates];
        }

        $sp = [
            'entityId' => self::entityId($tenantId),
            'assertionConsumerService' => ['url' => self::acsUrl($tenantId), 'binding' => Constants::BINDING_HTTP_POST],
            'singleLogoutService' => ['url' => self::sloUrl($tenantId), 'binding' => Constants::BINDING_HTTP_REDIRECT],
            'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
        ];

        if ($signsRequests) {
            $sp['x509cert'] = (string) $provider->sp_certificate;
            $sp['privateKey'] = (string) $provider->sp_private_key;
        }

        return [
            'strict' => true,
            'debug' => false,
            'sp' => $sp,
            'idp' => $idp,
            'security' => [
                'authnRequestsSigned' => $signsRequests,
                'logoutRequestSigned' => $signsRequests,
                'logoutResponseSigned' => $signsRequests,
                'wantAssertionsSigned' => true,
                'wantAssertionsEncrypted' => false,
                'wantNameId' => true,
                'wantNameIdEncrypted' => false,
                'wantXMLValidation' => true,
                'requestedAuthnContext' => false,
                'relaxDestinationValidation' => false,
                'destinationStrictlyMatches' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'allowRepeatAttributeName' => false,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];
    }
}
