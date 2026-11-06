<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use InvalidArgumentException;

/**
 * Opens the CAdES envelope of a .xml.p7m file. The signature is not verified: SDI already checked it on arrival.
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
        $trimmed = ltrim($content, "\xEF\xBB\xBF \t\r\n");

        if (str_starts_with($trimmed, '<')) {
            return $trimmed;
        }

        $der = $this->toDer($trimmed);
        $input = tempnam(sys_get_temp_dir(), 'p7m');
        $output = tempnam(sys_get_temp_dir(), 'xml');

        try {
            file_put_contents($input, $der);

            $opened = @openssl_cms_verify(
                $input,
                OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_NOSIGS | OPENSSL_CMS_BINARY,
                null,
                [],
                null,
                $output,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );

            $xml = $opened ? (string) file_get_contents($output) : '';
        } finally {
            @unlink($input);
            @unlink($output);
        }

        while (openssl_error_string() !== false) {
            continue;
        }

        if ($xml === '' || ! str_contains($xml, 'FatturaElettronica')) {
            throw new InvalidArgumentException('The p7m file does not contain a FatturaPA document.');
        }

        return ltrim($xml, "\xEF\xBB\xBF \t\r\n");
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
