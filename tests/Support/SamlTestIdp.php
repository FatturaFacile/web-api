<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * A throw-away SAML 2.0 identity provider for tests: a key and a self-signed certificate generated at runtime, IdP
 * metadata, and responses with a signed (or deliberately unsigned, forged, tampered) assertion. No fixed secrets.
 */
final class SamlTestIdp
{
    public const ENTITY_ID = 'https://saml.idp.example.com/entity';

    public const SSO_URL = 'https://saml.idp.example.com/sso';

    public const SLO_URL = 'https://saml.idp.example.com/slo';

    public string $privateKey;

    public string $certificate;

    public function __construct()
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($config);

        if ($key === false) {
            $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ff-openssl-minimal.cnf';
            file_put_contents($file, "[req]\ndistinguished_name=dn\n[dn]\n");
            $config['config'] = $file;
            $key = openssl_pkey_new($config);
        }

        $csr = openssl_csr_new(['commonName' => 'saml-test-idp'], $key, $config);
        $certificate = openssl_csr_sign($csr, null, $key, 2, $config);
        openssl_pkey_export($key, $privateKey, null, $config);
        openssl_x509_export($certificate, $certificatePem);

        $this->privateKey = $privateKey;
        $this->certificate = $certificatePem;
    }

    public function certificateBody(): string
    {
        return (string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $this->certificate);
    }

    public function metadataXml(): string
    {
        $cert = $this->certificateBody();
        $entity = self::ENTITY_ID;
        $sso = self::SSO_URL;
        $slo = self::SLO_URL;

        return <<<XML
<?xml version="1.0"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entity}">
  <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol" WantAuthnRequestsSigned="false">
    <md:KeyDescriptor use="signing">
      <ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:X509Data><ds:X509Certificate>{$cert}</ds:X509Certificate></ds:X509Data></ds:KeyInfo>
    </md:KeyDescriptor>
    <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$slo}"/>
    <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified</md:NameIDFormat>
    <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$sso}"/>
  </md:IDPSSODescriptor>
</md:EntityDescriptor>
XML;
    }

    /**
     * The id of the AuthnRequest inside the redirect URL a login start returned.
     */
    public static function requestIdOf(string $redirectUrl): string
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);
        $xml = (string) gzinflate((string) base64_decode((string) $query['SAMLRequest']));
        preg_match('/ID="([^"]+)"/', $xml, $match);

        return $match[1];
    }

    public static function relayStateOf(string $redirectUrl): string
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);

        return (string) $query['RelayState'];
    }

    /**
     * A base64 SAML response.
     *
     * Options: destination, audience, inResponseTo, nameId, attributes (name => list of values), assertionId,
     * notOnOrAfter (unix time), notBefore (unix time), sign (bool, default true), tamper (replaces the NameID after signing).
     *
     * @param  array<string, mixed>  $options
     */
    public function response(array $options): string
    {
        $now = time();
        $assertionId = $options['assertionId'] ?? '_a'.bin2hex(random_bytes(12));
        $nameId = htmlspecialchars((string) $options['nameId'], ENT_XML1);
        $destination = $options['destination'];
        $audience = $options['audience'];
        $inResponseTo = $options['inResponseTo'];
        $notOnOrAfter = gmdate('Y-m-d\TH:i:s\Z', $options['notOnOrAfter'] ?? $now + 300);
        $notBefore = gmdate('Y-m-d\TH:i:s\Z', $options['notBefore'] ?? $now - 30);
        $instant = gmdate('Y-m-d\TH:i:s\Z', $now);
        $issuer = self::ENTITY_ID;

        $attributes = '';

        foreach ((array) ($options['attributes'] ?? []) as $name => $values) {
            $attributes .= '<saml:Attribute Name="'.htmlspecialchars((string) $name, ENT_XML1).'">';

            foreach ((array) $values as $value) {
                $attributes .= '<saml:AttributeValue xsi:type="xs:string">'.htmlspecialchars((string) $value, ENT_XML1).'</saml:AttributeValue>';
            }

            $attributes .= '</saml:Attribute>';
        }

        $xml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_r{$assertionId}" Version="2.0" IssueInstant="{$instant}" Destination="{$destination}" InResponseTo="{$inResponseTo}">
  <saml:Issuer>{$issuer}</saml:Issuer>
  <samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>
  <saml:Assertion xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xs="http://www.w3.org/2001/XMLSchema" ID="{$assertionId}" Version="2.0" IssueInstant="{$instant}">
    <saml:Issuer>{$issuer}</saml:Issuer>
    <saml:Subject>
      <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">{$nameId}</saml:NameID>
      <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
        <saml:SubjectConfirmationData NotOnOrAfter="{$notOnOrAfter}" Recipient="{$destination}" InResponseTo="{$inResponseTo}"/>
      </saml:SubjectConfirmation>
    </saml:Subject>
    <saml:Conditions NotBefore="{$notBefore}" NotOnOrAfter="{$notOnOrAfter}">
      <saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction>
    </saml:Conditions>
    <saml:AuthnStatement AuthnInstant="{$instant}" SessionIndex="_s{$assertionId}">
      <saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:Password</saml:AuthnContextClassRef></saml:AuthnContext>
    </saml:AuthnStatement>
    <saml:AttributeStatement>{$attributes}</saml:AttributeStatement>
  </saml:Assertion>
</samlp:Response>
XML;

        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->loadXML($xml);

        if (($options['sign'] ?? true) === true) {
            $this->signAssertion($document);
        }

        if (isset($options['tamper'])) {
            $document->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'NameID')->item(0)->nodeValue = $options['tamper'];
        }

        return base64_encode((string) $document->saveXML($document->documentElement));
    }

    /**
     * A signed LogoutRequest query string for the HTTP-Redirect binding, or an unsigned one.
     *
     * @return array<string, string>
     */
    public function logoutQuery(string $destination, string $nameId, bool $sign = true): array
    {
        $instant = gmdate('Y-m-d\TH:i:s\Z');
        $issuer = self::ENTITY_ID;
        $name = htmlspecialchars($nameId, ENT_XML1);
        $xml = '<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_lo'.bin2hex(random_bytes(8)).'" Version="2.0" IssueInstant="'.$instant.'" Destination="'.$destination.'">'
            .'<saml:Issuer>'.$issuer.'</saml:Issuer><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">'.$name.'</saml:NameID></samlp:LogoutRequest>';
        $query = ['SAMLRequest' => base64_encode((string) gzdeflate($xml))];

        if (! $sign) {
            return $query;
        }

        $algorithm = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
        $toSign = 'SAMLRequest='.urlencode($query['SAMLRequest']).'&SigAlg='.urlencode($algorithm);
        openssl_sign($toSign, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $query + ['SigAlg' => $algorithm, 'Signature' => base64_encode($signature)];
    }

    private function signAssertion(DOMDocument $document): void
    {
        $assertion = $document->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Assertion')->item(0);
        $subject = $document->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Subject')->item(0);

        $signature = new XMLSecurityDSig;
        $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $signature->addReference(
            $assertion,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false]
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($this->privateKey);
        $signature->sign($key);
        $signature->add509Cert($this->certificate, true);
        $signature->insertSignature($assertion, $subject);
    }
}
