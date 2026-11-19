<?php

declare(strict_types=1);

namespace Modules\SDI\Signature;

use DOMDocument;
use DOMElement;
use Modules\SDI\Contracts\SignatureProviderInterface;
use Modules\SDI\DTOs\SignedPayload;
use RuntimeException;

/**
 * Signs with a local PKCS#12 (.p12/.pfx) certificate through OpenSSL: CAdES-BES via CMS (SHA-256) and XAdES-BES via
 * XMLDSig with a QualifyingProperties object.
 */
final class LocalPkcs12SignatureProvider implements SignatureProviderInterface
{
    private const string DsNs = 'http://www.w3.org/2000/09/xmldsig#';

    private const string XadesNs = 'http://uri.etsi.org/01903/v1.3.2#';

    private string $certificate;

    private \OpenSSLAsymmetricKey $privateKey;

    public function __construct(string $pkcs12, string $password)
    {
        $parts = [];

        if (! openssl_pkcs12_read($pkcs12, $parts, $password) || ! isset($parts['cert'], $parts['pkey'])) {
            throw new RuntimeException('The PKCS#12 certificate cannot be opened.');
        }

        $key = openssl_pkey_get_private($parts['pkey']);

        if ($key === false) {
            throw new RuntimeException('The private key cannot be read.');
        }

        $this->certificate = $parts['cert'];
        $this->privateKey = $key;
    }

    /**
     * Self-signed PKCS#12 for tests and the sandbox signer.
     *
     * @return array{pkcs12: string, password: string}
     */
    public static function generateSelfSigned(string $commonName = 'FatturaFacile Sandbox'): array
    {
        // A minimal config keeps this independent of a system openssl.cnf (missing on some hosts).
        $config = tempnam(sys_get_temp_dir(), 'ffcnf');
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nbasicConstraints=CA:FALSE\n");

        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $key = openssl_pkey_new($options);
            $csr = $key !== false ? openssl_csr_new(['commonName' => $commonName, 'countryName' => 'IT'], $key, $options) : false;
            $cert = $csr !== false ? openssl_csr_sign($csr, null, $key, 1, $options) : false;
            $output = '';
            $password = bin2hex(random_bytes(8));

            if ($key === false || $csr === false || $cert === false || ! openssl_pkcs12_export($cert, $output, $key, $password)) {
                throw new RuntimeException('Unable to generate a sandbox certificate.');
            }

            return ['pkcs12' => $output, 'password' => $password];
        } finally {
            @unlink($config);
        }
    }

    public function signCades(string $xml, string $fileName): SignedPayload
    {
        $in = tempnam(sys_get_temp_dir(), 'ffin');
        $out = tempnam(sys_get_temp_dir(), 'ffout');

        try {
            file_put_contents($in, $xml);

            if (! openssl_cms_sign($in, $out, $this->certificate, $this->privateKey, [], OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER)) {
                throw new RuntimeException('CAdES signature failed.');
            }

            return new SignedPayload((string) file_get_contents($out), $fileName.'.p7m', 'application/pkcs7-mime');
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    public function signXades(string $xml, string $fileName): SignedPayload
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->loadXML($xml, LIBXML_NONET);
        $root = $doc->documentElement;

        if (! $root instanceof DOMElement) {
            throw new RuntimeException('Empty document.');
        }

        $id = 'xmldsig-'.bin2hex(random_bytes(6));
        $docDigest = base64_encode(hash('sha256', $doc->C14N(), true));
        $certDer = $this->certificateDer();

        $signature = $doc->createElementNS(self::DsNs, 'ds:Signature');
        $signature->setAttribute('Id', $id);
        $root->appendChild($signature);

        $signedInfo = $doc->createElementNS(self::DsNs, 'ds:SignedInfo');
        $signature->appendChild($signedInfo);
        $this->child($doc, $signedInfo, 'ds:CanonicalizationMethod', null, ['Algorithm' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315']);
        $this->child($doc, $signedInfo, 'ds:SignatureMethod', null, ['Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256']);

        $object = $doc->createElementNS(self::DsNs, 'ds:Object');
        $qualifying = $doc->createElementNS(self::XadesNs, 'xades:QualifyingProperties');
        $qualifying->setAttribute('Target', '#'.$id);
        $signed = $doc->createElementNS(self::XadesNs, 'xades:SignedProperties');
        $signed->setAttribute('Id', $id.'-signedprops');
        $signedSig = $doc->createElementNS(self::XadesNs, 'xades:SignedSignatureProperties');
        $this->child($doc, $signedSig, 'xades:SigningTime', gmdate('Y-m-d\TH:i:s\Z'), [], self::XadesNs);
        $signingCert = $doc->createElementNS(self::XadesNs, 'xades:SigningCertificate');
        $cert = $doc->createElementNS(self::XadesNs, 'xades:Cert');
        $certDigest = $doc->createElementNS(self::XadesNs, 'xades:CertDigest');
        $this->child($doc, $certDigest, 'ds:DigestMethod', null, ['Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256']);
        $this->child($doc, $certDigest, 'ds:DigestValue', base64_encode(hash('sha256', $certDer, true)));
        $cert->appendChild($certDigest);
        $signingCert->appendChild($cert);
        $signedSig->appendChild($signingCert);
        $signed->appendChild($signedSig);
        $qualifying->appendChild($signed);
        $object->appendChild($qualifying);

        $keyInfo = $doc->createElementNS(self::DsNs, 'ds:KeyInfo');
        $x509 = $doc->createElementNS(self::DsNs, 'ds:X509Data');
        $this->child($doc, $x509, 'ds:X509Certificate', base64_encode($certDer));
        $keyInfo->appendChild($x509);

        $signature->appendChild($keyInfo);
        $signature->appendChild($object);

        $this->reference($doc, $signedInfo, '', $docDigest, true);
        $this->reference($doc, $signedInfo, '#'.$id.'-signedprops', base64_encode(hash('sha256', $signed->C14N(), true)), false, 'http://uri.etsi.org/01903#SignedProperties');

        $value = '';
        openssl_sign($signedInfo->C14N(), $value, $this->privateKey, OPENSSL_ALGO_SHA256);
        $signatureValue = $doc->createElementNS(self::DsNs, 'ds:SignatureValue', base64_encode($value));
        $signature->insertBefore($signatureValue, $keyInfo);

        return new SignedPayload((string) $doc->saveXML(), $fileName, 'application/xml');
    }

    private function certificateDer(): string
    {
        $pem = '';
        openssl_x509_export($this->certificate, $pem);

        return (string) base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem) ?? '', true);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function child(DOMDocument $doc, DOMElement $parent, string $name, ?string $text = null, array $attributes = [], string $ns = self::DsNs): DOMElement
    {
        $element = $doc->createElementNS($ns, $name, $text ?? '');

        foreach ($attributes as $key => $value) {
            $element->setAttribute($key, $value);
        }

        $parent->appendChild($element);

        return $element;
    }

    private function reference(DOMDocument $doc, DOMElement $signedInfo, string $uri, string $digest, bool $enveloped, ?string $type = null): void
    {
        $reference = $this->child($doc, $signedInfo, 'ds:Reference', null, ['URI' => $uri] + ($type !== null ? ['Type' => $type] : []));

        if ($enveloped) {
            $transforms = $this->child($doc, $reference, 'ds:Transforms');
            $this->child($doc, $transforms, 'ds:Transform', null, ['Algorithm' => 'http://www.w3.org/2000/09/xmldsig#enveloped-signature']);
        }

        $this->child($doc, $reference, 'ds:DigestMethod', null, ['Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256']);
        $this->child($doc, $reference, 'ds:DigestValue', $digest);
    }
}
