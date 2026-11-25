<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use DateTimeImmutable;
use DateTimeZone;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Fpdf\Fpdf;
use Modules\ElectronicSignature\DTOs\PdfSignatureInfo;
use Modules\ElectronicSignature\DTOs\PreparedPdf;
use Modules\ElectronicSignature\DTOs\SignatureAppearance;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

/**
 * The cryptographic side of the PDF: digests, visual stamps, and PDF signatures written as incremental updates.
 *
 * A signature is a /Sig dictionary whose /Contents is a detached CMS (SubFilter adbe.pkcs7.detached) over the bytes of the file
 * outside that hole (the /ByteRange). {@see prepare()} appends the field with an empty hole and returns the digest of the
 * ByteRange; whoever signs (a local key, or a trust service provider that only sees the digest) hands back a CMS and
 * {@see embed()} puts it in place. Because every signature is a new incremental revision, earlier signatures stay valid
 * and a document can carry several of them.
 *
 * The files handled here are the ones this application writes (FPDF / FPDI / dompdf output with a classic cross-reference
 * table); {@see stamp()} normalises any other PDF into that shape first. Long-term validation data (CRL / OCSP, document
 * time stamps) is added by the qualified provider that signs, not by the local sealing.
 */
final class PdfSignatureService
{
    /** Bytes reserved for the CMS. A CMS with a certificate chain, signed attributes and a time stamp fits well within it. */
    public const int SignatureCapacity = 16384;

    private const string ByteRangePlaceholder = '[0 0000000000 0000000000 0000000000]';

    /**
     * FPDI extends the global `FPDF` class, while the fpdf/fpdf package ships it namespaced (`Fpdf\Fpdf`).
     */
    public static function registerFpdf(): void
    {
        if (! class_exists('FPDF', false)) {
            class_alias(Fpdf::class, 'FPDF');
        }
    }

    public function sha256(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Draws the signature boxes (and, when given, a verification QR code) on the pages and returns a clean, single revision
     * PDF. Any previous signature is dropped by the rewrite, so stamp before signing.
     *
     * @param  list<array{box: array{page: int, x: float, y: float, width: float, height: float}, lines: list<string>, qr?: string|null, image?: string|null}>  $stamps
     */
    public function stamp(string $pdf, array $stamps = []): string
    {
        $qrFiles = [];
        self::registerFpdf();

        try {
            $fpdi = new Fpdi('P', 'pt');
            $fpdi->SetAutoPageBreak(false);
            $fpdi->SetCreator('FatturaFacile');
            $pages = $fpdi->setSourceFile(StreamReader::createByString($pdf));

            for ($page = 1; $page <= $pages; $page++) {
                $template = $fpdi->importPage($page);
                $size = $fpdi->getTemplateSize($template);
                $width = (float) $size['width'];
                $height = (float) $size['height'];
                $fpdi->AddPage($width > $height ? 'L' : 'P', [$width, $height]);
                $fpdi->useTemplate($template);

                foreach ($stamps as $stamp) {
                    if ($stamp['box']['page'] === $page) {
                        $this->drawStamp($fpdi, $stamp, $width, $height, $qrFiles);
                    }
                }
            }

            return $fpdi->Output('S');
        } catch (SignatureException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SignatureException('unsupported_pdf');
        } finally {
            foreach ($qrFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * @return array<int, array{width: float, height: float}> page sizes in points, keyed by page number
     */
    public function pageSizes(string $pdf): array
    {
        self::registerFpdf();

        try {
            $fpdi = new Fpdi('P', 'pt');
            $pages = $fpdi->setSourceFile(StreamReader::createByString($pdf));
            $sizes = [];

            for ($page = 1; $page <= $pages; $page++) {
                $size = $fpdi->getTemplateSize($fpdi->importPage($page));
                $sizes[$page] = ['width' => (float) $size['width'], 'height' => (float) $size['height']];
            }

            return $sizes;
        } catch (Throwable) {
            throw new SignatureException('unsupported_pdf');
        }
    }

    /**
     * Appends an empty signature field (visible when the appearance has a box) and returns the digest to sign.
     */
    public function prepare(string $pdf, SignatureAppearance $appearance): PreparedPdf
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new SignatureException('unsupported_pdf');
        }

        $pdf = rtrim($pdf, "\r\n")."\n";
        $trailer = $this->lastTrailer($pdf);
        $root = $trailer['root'];
        $catalog = $this->objectBody($pdf, $root) ?? throw new SignatureException('unsupported_pdf');
        $pageNumber = $appearance->box['page'] ?? 1;
        $pageObject = $this->pageObject($pdf, $catalog, $pageNumber) ?? throw new SignatureException('unsupported_pdf');
        $pageBody = $this->objectBody($pdf, $pageObject) ?? throw new SignatureException('unsupported_pdf');

        $signatureObject = $trailer['size'];
        $widgetObject = $signatureObject + 1;
        $appearanceObject = $signatureObject + 2;
        $objects = [];

        $mediaBox = $this->mediaBox($pdf, $pageBody);
        $rect = [0.0, 0.0, 0.0, 0.0];
        $hasAppearance = $appearance->box !== null;

        if ($appearance->box !== null) {
            $width = $mediaBox[2] - $mediaBox[0];
            $height = $mediaBox[3] - $mediaBox[1];
            $left = $mediaBox[0] + $appearance->box['x'] * $width;
            $top = $mediaBox[3] - $appearance->box['y'] * $height;
            $rect = [$left, $top - $appearance->box['height'] * $height, $left + $appearance->box['width'] * $width, $top];
            $objects[$appearanceObject] = $this->appearanceStream($rect[2] - $rect[0], $rect[3] - $rect[1], $appearance->lines);
        }

        $objects[$signatureObject] = $this->signatureDictionary($appearance);
        $objects[$widgetObject] = '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature'.$widgetObject.') /F 132'
            .' /Rect ['.$this->number($rect[0]).' '.$this->number($rect[1]).' '.$this->number($rect[2]).' '.$this->number($rect[3]).']'
            ." /P {$pageObject} 0 R /V {$signatureObject} 0 R"
            .($hasAppearance ? " /AP << /N {$appearanceObject} 0 R >>" : '').' >>';

        $objects[$pageObject] = $this->withAnnotation($pageBody, $widgetObject);
        $objects += $this->withAcroFormField($pdf, $root, $catalog, $widgetObject);

        $appended = $this->appendRevision($pdf, $objects, $trailer, $hasAppearance ? $appearanceObject + 1 : $appearanceObject);
        $signaturePosition = strpos($appended['bytes'], '/Type /Sig', $appended['offsets'][$signatureObject]);
        $contentsKeyword = strpos($appended['bytes'], '/Contents <', (int) $signaturePosition);

        if ($signaturePosition === false || $contentsKeyword === false) {
            throw new SignatureException('unsupported_pdf');
        }

        $contentsOffset = $contentsKeyword + strlen('/Contents ');
        $contentsLength = 2 * self::SignatureCapacity + 2;
        $contentsEnd = $contentsOffset + $contentsLength;
        $bytes = $appended['bytes'];
        $total = strlen($bytes);
        $byteRange = sprintf('[0 %010d %010d %010d]', $contentsOffset, $contentsEnd, $total - $contentsEnd);
        $placeholderAt = strpos($bytes, self::ByteRangePlaceholder, $appended['offsets'][$signatureObject]);

        if ($placeholderAt === false) {
            throw new SignatureException('unsupported_pdf');
        }

        $bytes = substr_replace($bytes, $byteRange, $placeholderAt, strlen(self::ByteRangePlaceholder));

        return new PreparedPdf($bytes, $contentsOffset, $contentsLength, $this->byteRangeDigest($bytes, $contentsOffset, $contentsEnd));
    }

    /**
     * Writes the CMS (DER) into the hole of a prepared PDF.
     */
    public function embed(PreparedPdf $prepared, string $cms): string
    {
        $hex = strtoupper(bin2hex($cms));

        if (strlen($hex) > $prepared->contentsLength - 2) {
            throw new SignatureException('signature_too_large');
        }

        return substr_replace($prepared->bytes, '<'.str_pad($hex, $prepared->contentsLength - 2, '0').'>', $prepared->contentsOffset, $prepared->contentsLength);
    }

    /**
     * Signs a PDF with a local key: prepare, CMS over the ByteRange with the private key, embed.
     */
    public function signWithKey(string $pdf, SignatureAppearance $appearance, string $certificatePem, string $privateKeyPem, string $chainPem = ''): string
    {
        $prepared = $this->prepare($pdf, $appearance);

        return $this->embed($prepared, $this->cmsForPrepared($prepared, $certificatePem, $privateKeyPem, $chainPem));
    }

    /**
     * The detached CMS (DER) over the ByteRange of a prepared PDF, signed with a local private key.
     */
    public function cmsForPrepared(PreparedPdf $prepared, string $certificatePem, string $privateKeyPem, string $chainPem = ''): string
    {
        $content = $this->byteRangeContent($prepared->bytes, $prepared->contentsOffset, $prepared->contentsOffset + $prepared->contentsLength);
        $input = $this->temporaryFile($content);
        $output = $this->temporaryFile('');
        $chain = $chainPem !== '' ? $this->temporaryFile($chainPem) : null;

        try {
            $signed = openssl_cms_sign(
                $input,
                $output,
                $certificatePem,
                [$privateKeyPem, ''],
                [],
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
                $chain,
            );

            if (! $signed) {
                throw new SignatureException('seal_failed', 500);
            }

            return (string) file_get_contents($output);
        } finally {
            @unlink($input);
            @unlink($output);
            if ($chain !== null) {
                @unlink($chain);
            }
        }
    }

    /**
     * Rebuilds the handle of a prepared PDF that was kept as bytes (a signature that waits for the signer): the hole is the
     * one the last ByteRange leaves out.
     */
    public function preparedFromBytes(string $bytes): PreparedPdf
    {
        if (preg_match_all('#/ByteRange\s*\[\s*0\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $bytes, $matches, PREG_SET_ORDER) < 1) {
            throw new SignatureException('unsupported_pdf');
        }

        $last = $matches[count($matches) - 1];
        $offset = (int) $last[1];
        $end = (int) $last[2];

        return new PreparedPdf($bytes, $offset, $end - $offset, $this->byteRangeDigest($bytes, $offset, $end));
    }

    /**
     * Checks every signature of a PDF: the CMS against the bytes it covers (any change since signing breaks it) and the
     * certificate it carries. The certificate chain is not validated against a trust list: a positive answer proves the
     * document was not changed and shows who the certificate names, the trust status of the issuer is the verifier's call.
     *
     * @return list<PdfSignatureInfo>
     */
    public function inspect(string $pdf): array
    {
        $signatures = [];
        $total = strlen($pdf);
        preg_match_all('#/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $pdf, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $index => $match) {
            $from = (int) $match[1][0];
            $length1 = (int) $match[2][0];
            $from2 = (int) $match[3][0];
            $length2 = (int) $match[4][0];

            if ($from !== 0 || $length1 <= 0 || $from2 <= $length1 || $from2 + $length2 > $total) {
                $signatures[] = $this->unreadable($index);

                continue;
            }

            $hole = substr($pdf, $length1, $from2 - $length1);
            $hex = trim($hole, "<> \r\n");
            $cms = $this->derFromHex($hex);
            $dictionary = substr($pdf, max(0, $match[0][1] - 200), 400).substr($pdf, $from2, 600);
            $reason = $this->stringEntry($dictionary, 'Reason');
            $subFilter = preg_match('#/SubFilter\s*/([A-Za-z0-9.\-_]+)#', $dictionary, $sub) === 1 ? $sub[1] : 'unknown';
            $content = substr($pdf, 0, $length1).substr($pdf, $from2, $length2);
            $signatures[] = $this->inspectOne($index, $cms, $content, $from2 + $length2 === $total, $subFilter, $reason, $this->signingTime($dictionary));
        }

        return $signatures;
    }

    /**
     * Parses an ASN.1 DER blob out of the hex of a /Contents hole (zero padded at the end).
     */
    private function derFromHex(string $hex): string
    {
        $binary = (string) hex2bin(strlen($hex) % 2 === 0 ? $hex : $hex.'0');

        if ($binary === '' || $binary[0] !== "\x30") {
            return '';
        }

        $lengthByte = ord($binary[1]);

        if ($lengthByte < 0x80) {
            return substr($binary, 0, 2 + $lengthByte);
        }

        $count = $lengthByte & 0x7F;
        $length = 0;

        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($binary[2 + $i]);
        }

        return substr($binary, 0, 2 + $count + $length);
    }

    private function inspectOne(int $index, string $cms, string $content, bool $coversWholeDocument, string $subFilter, ?string $reason, ?string $signingTime): PdfSignatureInfo
    {
        if ($cms === '') {
            return $this->unreadable($index);
        }

        $cmsFile = $this->temporaryFile($cms);
        $contentFile = $this->temporaryFile($content);

        try {
            // The signature file is a detached CMS: verifies the signature and the digest of the signed attributes against
            // the bytes; the chain to a trust anchor is not checked (NOVERIFY).
            $intact = (bool) @openssl_cms_verify(
                $contentFile,
                OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED,
                null,
                [],
                null,
                null,
                null,
                $cmsFile,
                OPENSSL_ENCODING_DER,
            );
        } finally {
            @unlink($cmsFile);
            @unlink($contentFile);
        }

        $certificates = $this->certificatesOf($cms);
        $parsed = $certificates !== [] ? openssl_x509_parse($certificates[0]) : false;
        $subject = is_array($parsed) ? (array) ($parsed['subject'] ?? []) : [];
        $issuer = is_array($parsed) ? (array) ($parsed['issuer'] ?? []) : [];

        return new PdfSignatureInfo(
            index: $index,
            intact: $intact,
            coversWholeDocument: $coversWholeDocument,
            signerName: isset($subject['CN']) ? (string) $subject['CN'] : null,
            certificateSerial: is_array($parsed) ? (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? '') : null,
            certificateSubject: $this->distinguishedName($subject),
            certificateIssuer: $this->distinguishedName($issuer),
            validFrom: is_array($parsed) && isset($parsed['validFrom_time_t']) ? gmdate('c', (int) $parsed['validFrom_time_t']) : null,
            validTo: is_array($parsed) && isset($parsed['validTo_time_t']) ? gmdate('c', (int) $parsed['validTo_time_t']) : null,
            signingTime: $signingTime,
            subFilter: $subFilter,
            reason: $reason,
        );
    }

    /**
     * The `/M` entry of a signature dictionary as an ISO 8601 time.
     */
    private function signingTime(string $dictionary): ?string
    {
        if (preg_match('#/M\s*\(D:(\d{14})#', $dictionary, $match) !== 1) {
            return null;
        }

        $time = DateTimeImmutable::createFromFormat('YmdHis', $match[1], new DateTimeZone('UTC'));

        return $time === false ? null : $time->format(DATE_ATOM);
    }

    /**
     * The certificates carried by a CMS SignedData (DER), as PEM, signer first. A minimal ASN.1 walk: a definite-length DER
     * encoding is expected.
     *
     * @return list<string>
     */
    private function certificatesOf(string $cms): array
    {
        $root = $this->tlv($cms, 0);
        $position = $root['value'];
        $end = $root['value'] + $root['length'];

        // ContentInfo: contentType OID, then [0] EXPLICIT SignedData
        $oid = $this->tlv($cms, $position);
        $explicit = $oid === null ? null : $this->tlv($cms, $oid['value'] + $oid['length']);

        if ($root === null || $explicit === null || $explicit['tag'] !== 0xA0) {
            return [];
        }

        $signedData = $this->tlv($cms, $explicit['value']);

        if ($signedData === null || $end < $signedData['value']) {
            return [];
        }

        $cursor = $signedData['value'];
        $limit = $signedData['value'] + $signedData['length'];

        while ($cursor < $limit) {
            $child = $this->tlv($cms, $cursor);

            if ($child === null) {
                return [];
            }

            if ($child['tag'] === 0xA0) {
                $certificates = [];
                $inner = $child['value'];
                $innerEnd = $child['value'] + $child['length'];

                while ($inner < $innerEnd) {
                    $certificate = $this->tlv($cms, $inner);

                    if ($certificate === null) {
                        break;
                    }

                    $der = substr($cms, $inner, $certificate['value'] - $inner + $certificate['length']);
                    $certificates[] = '-----BEGIN CERTIFICATE-----
'.chunk_split(base64_encode($der), 64, '
').'-----END CERTIFICATE-----
';
                    $inner = $certificate['value'] + $certificate['length'];
                }

                return $certificates;
            }

            $cursor = $child['value'] + $child['length'];
        }

        return [];
    }

    /**
     * @return array{tag: int, length: int, value: int}|null null for an indefinite or truncated element
     */
    private function tlv(string $der, int $position): ?array
    {
        if ($position + 2 > strlen($der)) {
            return null;
        }

        $tag = ord($der[$position]);
        $first = ord($der[$position + 1]);

        if ($first === 0x80) {
            return null;
        }

        if ($first < 0x80) {
            return ['tag' => $tag, 'length' => $first, 'value' => $position + 2];
        }

        $count = $first & 0x7F;
        $length = 0;

        for ($i = 0; $i < $count; $i++) {
            if (! isset($der[$position + 2 + $i])) {
                return null;
            }

            $length = ($length << 8) | ord($der[$position + 2 + $i]);
        }

        return ['tag' => $tag, 'length' => $length, 'value' => $position + 2 + $count];
    }

    private function unreadable(int $index): PdfSignatureInfo
    {
        return new PdfSignatureInfo($index, false, false, null, null, null, null, null, null, null, 'unknown', null);
    }

    /**
     * @param  array<string, mixed>  $name
     */
    private function distinguishedName(array $name): ?string
    {
        if ($name === []) {
            return null;
        }

        $parts = [];

        foreach ($name as $key => $value) {
            $parts[] = $key.'='.(is_array($value) ? implode('/', array_map('strval', $value)) : (string) $value);
        }

        return implode(', ', $parts);
    }

    private function stringEntry(string $dictionary, string $key): ?string
    {
        return preg_match('#/'.$key.'\s*\(((?:[^()\\\\]|\\\\.)*)\)#s', $dictionary, $match) === 1 ? stripslashes($match[1]) : null;
    }

    /**
     * @param  array{box: array{page: int, x: float, y: float, width: float, height: float}, lines: list<string>, qr?: string|null, image?: string|null}  $stamp
     * @param  list<string>  $qrFiles
     */
    private function drawStamp(Fpdi $fpdi, array $stamp, float $pageWidth, float $pageHeight, array &$qrFiles): void
    {
        $box = $stamp['box'];
        $x = $box['x'] * $pageWidth;
        $y = $box['y'] * $pageHeight;
        $width = max(40.0, $box['width'] * $pageWidth);
        $height = max(30.0, $box['height'] * $pageHeight);

        $fpdi->SetDrawColor(30, 64, 124);
        $fpdi->SetFillColor(244, 247, 252);
        $fpdi->SetLineWidth(0.6);
        $fpdi->Rect($x, $y, $width, $height, 'DF');

        $textLeft = $x + 4;
        $textWidth = $width - 8;

        if (($stamp['image'] ?? null) !== null && $stamp['image'] !== '') {
            // The drawn signature of the signer, on the left of the box.
            $side = min($height - 8, $width * 0.34);
            $file = $this->temporaryFile($stamp['image']);
            $qrFiles[] = $file;

            try {
                $fpdi->Image($file, $x + 4, $y + 4, $side, $side, 'PNG');
                $textLeft = $x + $side + 8;
                $textWidth = $width - $side - 12;
            } catch (Throwable) {
                // An image FPDF cannot read is left out; the typed lines still show who signed.
            }
        }

        if (($stamp['qr'] ?? null) !== null && $stamp['qr'] !== '') {
            $side = min($height - 8, 46.0);
            $file = $this->temporaryFile($this->qrPng($stamp['qr']));
            $qrFiles[] = $file;
            $fpdi->Image($file, $x + $width - $side - 4, $y + 4, $side, $side, 'PNG');
            $textWidth -= $side + 8;
        }

        $fpdi->SetTextColor(20, 30, 60);
        $fpdi->SetFont('Helvetica', '', 6.5);
        $lineHeight = 8.0;
        $cursor = $y + 4;

        foreach ($stamp['lines'] as $position => $line) {
            if ($cursor + $lineHeight > $y + $height - 2) {
                break;
            }

            $fpdi->SetFont('Helvetica', $position === 0 ? 'B' : '', $position === 0 ? 7.5 : 6.5);
            $fpdi->SetXY($textLeft, $cursor);
            $fpdi->Cell($textWidth, $lineHeight, $this->latin1($this->fit($line, $textWidth)), 0, 0, 'L');
            $cursor += $lineHeight;
        }
    }

    private function fit(string $line, float $width): string
    {
        $limit = max(8, (int) floor($width / 3.2));

        return mb_strlen($line) > $limit ? mb_substr($line, 0, $limit - 1).'…' : $line;
    }

    private function qrPng(string $data): string
    {
        return (new PngWriter)->write(new QrCode(data: $data, size: 160, margin: 0))->getString();
    }

    private function latin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '' : $converted;
    }

    /**
     * @param  list<string>  $lines
     */
    private function appearanceStream(float $width, float $height, array $lines): string
    {
        $content = "q 0.957 0.969 0.988 rg 0 0 {$this->number($width)} {$this->number($height)} re f Q\n"
            ."q 0.118 0.251 0.486 RG 0.8 w 0.4 0.4 {$this->number($width - 0.8)} {$this->number($height - 0.8)} re S Q\n";
        $y = $height - 10.0;

        foreach ($lines as $position => $line) {
            if ($y < 4) {
                break;
            }

            $content .= 'BT /F1 '.($position === 0 ? '8' : '6.5')." Tf 0.08 0.12 0.24 rg 4 {$this->number($y)} Td (".$this->escape($this->latin1($line)).") Tj ET\n";
            $y -= 9.0;
        }

        return '<< /Type /XObject /Subtype /Form /BBox [0 0 '.$this->number($width).' '.$this->number($height).'] '
            .'/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >> >> >> '
            .'/Length '.strlen($content).' >>'."\nstream\n".$content.'endstream';
    }

    private function signatureDictionary(SignatureAppearance $appearance): string
    {
        $date = $appearance->signedAt->setTimezone(new DateTimeZone('UTC'))->format('YmdHis');

        return '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange '.self::ByteRangePlaceholder
            .' /Contents <'.str_repeat('0', 2 * self::SignatureCapacity).'>'
            ." /M (D:{$date}Z)"
            .' /Name ('.$this->escape($this->latin1($appearance->signerName)).')'
            .' /Reason ('.$this->escape($this->latin1($appearance->reason)).')'
            .($appearance->location !== null ? ' /Location ('.$this->escape($this->latin1($appearance->location)).')' : '')
            .' >>';
    }

    /**
     * Appends objects, a cross-reference section and a trailer to the file.
     *
     * @param  array<int, string>  $objects  object number => body
     * @param  array{root: int, size: int, prev: int, info: int|null}  $trailer
     * @return array{bytes: string, offsets: array<int, int>}
     */
    private function appendRevision(string $pdf, array $objects, array $trailer, int $newSize): array
    {
        ksort($objects);
        $bytes = $pdf;
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($bytes);
            $bytes .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($bytes);
        $bytes .= "xref\n";
        $numbers = array_keys($objects);
        $count = count($numbers);

        for ($i = 0; $i < $count;) {
            $start = $numbers[$i];
            $end = $i;

            while ($end + 1 < $count && $numbers[$end + 1] === $numbers[$end] + 1) {
                $end++;
            }

            $bytes .= $start.' '.($end - $i + 1)."\n";

            for ($k = $i; $k <= $end; $k++) {
                $bytes .= sprintf("%010d 00000 n \n", $offsets[$numbers[$k]]);
            }

            $i = $end + 1;
        }

        $bytes .= 'trailer'."\n".'<< /Size '.max($newSize, $trailer['size'] + 1).' /Root '.$trailer['root'].' 0 R /Prev '.$trailer['prev']
            .($trailer['info'] !== null ? ' /Info '.$trailer['info'].' 0 R' : '')." >>\nstartxref\n{$xref}\n%%EOF\n";

        return ['bytes' => $bytes, 'offsets' => $offsets];
    }

    /**
     * @return array{root: int, size: int, prev: int, info: int|null}
     */
    private function lastTrailer(string $pdf): array
    {
        $position = strrpos($pdf, "\ntrailer");
        $startxref = strrpos($pdf, 'startxref');

        if ($position === false || $startxref === false || $startxref < $position) {
            throw new SignatureException('unsupported_pdf');
        }

        $section = substr($pdf, $position, $startxref - $position);

        if (preg_match('#/Root\s+(\d+)\s+\d+\s+R#', $section, $root) !== 1 || preg_match('#/Size\s+(\d+)#', $section, $size) !== 1
            || preg_match('#startxref\s+(\d+)#', substr($pdf, $startxref, 40), $prev) !== 1) {
            throw new SignatureException('unsupported_pdf');
        }

        return [
            'root' => (int) $root[1],
            'size' => (int) $size[1],
            'prev' => (int) $prev[1],
            'info' => preg_match('#/Info\s+(\d+)\s+\d+\s+R#', $section, $info) === 1 ? (int) $info[1] : null,
        ];
    }

    /**
     * The latest definition of an object: the text between `N 0 obj` and `endobj`.
     */
    private function objectBody(string $pdf, int $number): ?string
    {
        $needle = "\n{$number} 0 obj";
        $start = strrpos($pdf, $needle);

        if ($start === false) {
            return null;
        }

        $bodyStart = $start + strlen($needle);
        $end = strpos($pdf, 'endobj', $bodyStart);

        return $end === false ? null : trim(substr($pdf, $bodyStart, $end - $bodyStart));
    }

    private function pageObject(string $pdf, string $catalog, int $pageNumber): ?int
    {
        if (preg_match('#/Pages\s+(\d+)\s+\d+\s+R#', $catalog, $pages) !== 1) {
            return null;
        }

        $found = [];
        $this->collectPages($pdf, (int) $pages[1], $found, 0);

        return $found[$pageNumber - 1] ?? null;
    }

    /**
     * @param  list<int>  $found
     */
    private function collectPages(string $pdf, int $node, array &$found, int $depth): void
    {
        $body = $this->objectBody($pdf, $node);

        if ($body === null || $depth > 8) {
            return;
        }

        if (preg_match('#/Type\s*/Pages#', $body) !== 1) {
            $found[] = $node;

            return;
        }

        if (preg_match('#/Kids\s*\[([^\]]*)\]#', $body, $kids) !== 1) {
            return;
        }

        preg_match_all('#(\d+)\s+\d+\s+R#', $kids[1], $refs);

        foreach ($refs[1] as $ref) {
            $this->collectPages($pdf, (int) $ref, $found, $depth + 1);
        }
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function mediaBox(string $pdf, string $pageBody): array
    {
        $box = $pageBody;

        if (preg_match('#/MediaBox\s*\[\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s*\]#', $box, $match) !== 1
            && preg_match('#/Parent\s+(\d+)\s+\d+\s+R#', $pageBody, $parent) === 1) {
            $box = (string) $this->objectBody($pdf, (int) $parent[1]);
            preg_match('#/MediaBox\s*\[\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s*\]#', $box, $match);
        }

        return isset($match[4]) ? [(float) $match[1], (float) $match[2], (float) $match[3], (float) $match[4]] : [0.0, 0.0, 595.28, 841.89];
    }

    private function withAnnotation(string $pageBody, int $widget): string
    {
        if (preg_match('#/Annots\s*\[#', $pageBody) === 1) {
            return (string) preg_replace('#(/Annots\s*\[)#', '$1'.$widget.' 0 R ', $pageBody, 1);
        }

        if (preg_match('#/Annots\s+\d+\s+\d+\s+R#', $pageBody) === 1) {
            throw new SignatureException('unsupported_pdf');
        }

        $end = strrpos($pageBody, '>>');

        if ($end === false) {
            throw new SignatureException('unsupported_pdf');
        }

        return substr_replace($pageBody, " /Annots [{$widget} 0 R] ", $end, 0);
    }

    /**
     * The catalog (and, when the form is a separate object, the form) with the new field added to /AcroForm /Fields.
     *
     * @return array<int, string>
     */
    private function withAcroFormField(string $pdf, int $root, string $catalog, int $widget): array
    {
        if (preg_match('#/AcroForm\s+(\d+)\s+\d+\s+R#', $catalog, $reference) === 1) {
            $form = $this->objectBody($pdf, (int) $reference[1]) ?? throw new SignatureException('unsupported_pdf');

            return [(int) $reference[1] => $this->withField($form, $widget)];
        }

        if (preg_match('#/AcroForm\s*<<#', $catalog) === 1) {
            return [$root => $this->withField($catalog, $widget)];
        }

        $end = strrpos($catalog, '>>');

        if ($end === false) {
            throw new SignatureException('unsupported_pdf');
        }

        return [$root => substr_replace($catalog, " /AcroForm << /Fields [{$widget} 0 R] /SigFlags 3 >> ", $end, 0)];
    }

    private function withField(string $body, int $widget): string
    {
        if (preg_match('#/Fields\s*\[#', $body) === 1) {
            $body = (string) preg_replace('#(/Fields\s*\[)#', '$1'.$widget.' 0 R ', $body, 1);
        } else {
            $body = (string) preg_replace('#(/AcroForm\s*<<)#', '$1 /Fields ['.$widget.' 0 R]', $body, 1);
        }

        return preg_match('#/SigFlags\s+\d+#', $body) === 1 ? (string) preg_replace('#/SigFlags\s+\d+#', '/SigFlags 3', $body, 1) : (string) preg_replace('#(/Fields\s*\[[^\]]*\])#', '$1 /SigFlags 3', $body, 1);
    }

    private function byteRangeDigest(string $bytes, int $contentsOffset, int $contentsEnd): string
    {
        return hash('sha256', $this->byteRangeContent($bytes, $contentsOffset, $contentsEnd), true);
    }

    private function byteRangeContent(string $bytes, int $contentsOffset, int $contentsEnd): string
    {
        return substr($bytes, 0, $contentsOffset).substr($bytes, $contentsEnd);
    }

    private function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
    }

    private function temporaryFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sig');

        if ($file === false) {
            throw new SignatureException('seal_failed', 500);
        }

        file_put_contents($file, $content);

        return $file;
    }
}
