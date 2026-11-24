<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use InvalidArgumentException;

/**
 * Opens the CAdES envelope of a .xml.p7m file and checks the integrity of its signature: the signed content must match
 * the signature of the certificate carried in the envelope. The chain of that certificate up to a qualified certification
 * authority is not checked, since no trusted list of those authorities is configured here.
 */
final class P7mExtractor
{
    /**
     * Return the FatturaPA XML of a file that may or may not be signed.
     *
     * @throws InvalidArgumentException when the file is neither XML nor a readable envelope
     */
    public function extract(string $content): string
    {
        return $this->open($content)['xml'];
    }

    /**
     * Return the FatturaPA XML and whether the signature of the envelope verifies (null for a file that is not signed).
     * A file whose signature does not verify is still opened, so the caller decides what to do with it.
     *
     * @return array{xml: string, signature_verified: bool|null}
     *
     * @throws InvalidArgumentException when the file is neither XML nor a readable envelope
     */
    public function open(string $content): array
    {
        $trimmed = ltrim($content, "\xEF\xBB\xBF \t\r\n");

        if (str_starts_with($trimmed, '<')) {
            return ['xml' => $trimmed, 'signature_verified' => null];
        }

        $der = $this->toDer($trimmed);

        // NOVERIFY skips the certificate chain only: the signature over the content is still checked.
        $verified = $this->content($der, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY);
        $xml = $verified ?? $this->content($der, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_NOSIGS | OPENSSL_CMS_BINARY) ?? '';

        if ($xml === '' || ! str_contains($xml, 'FatturaElettronica')) {
            throw new InvalidArgumentException('The p7m file does not contain a FatturaPA document.');
        }

        return ['xml' => ltrim($xml, "\xEF\xBB\xBF \t\r\n"), 'signature_verified' => $verified !== null];
    }

    /**
     * The content of the envelope, or null when OpenSSL refuses it with these flags.
     */
    private function content(string $der, int $flags): ?string
    {
        $input = tempnam(sys_get_temp_dir(), 'p7m');
        $output = tempnam(sys_get_temp_dir(), 'xml');

        try {
            file_put_contents($input, $der);

            $opened = @openssl_cms_verify($input, $flags, null, [], null, $output, null, null, OPENSSL_ENCODING_DER);

            $content = $opened ? (string) file_get_contents($output) : null;
        } finally {
            @unlink($input);
            @unlink($output);
        }

        while (openssl_error_string() !== false) {
            continue;
        }

        return $content === '' ? null : $content;
    }

    /**
     * Receipts are plain XML files that carry their signature inside the document.
     *
     * @throws InvalidArgumentException when the file is not readable
     */
    public function extractReceipt(string $content): string
    {
        $trimmed = ltrim($content, "\xEF\xBB\xBF \t\r\n");

        if (! str_starts_with($trimmed, '<')) {
            throw new InvalidArgumentException('The receipt is not an XML document.');
        }

        return $trimmed;
    }

    /**
     * Files are delivered either in binary DER, starting with an ASN.1 sequence, or encoded in base64.
     */
    private function toDer(string $content): string
    {
        if (str_starts_with($content, "\x30")) {
            return $content;
        }

        $decoded = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);

        if ($decoded === false || ! str_starts_with($decoded, "\x30")) {
            throw new InvalidArgumentException('The file is neither XML nor a p7m envelope.');
        }

        return $decoded;
    }
}
