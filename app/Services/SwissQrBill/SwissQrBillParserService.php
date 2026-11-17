<?php

declare(strict_types=1);

namespace App\Services\SwissQrBill;

use App\Contracts\SwissQrBill\SwissQrBillParserInterface;
use App\Exceptions\QrBillParseException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use Zxing\QrReader;

/**
 * Decodes Swiss QR-bills (SIX Swiss Payment Standards, SPC 0200) from raw payloads, raster images and PDFs.
 *
 * PDFs are searched for embedded raster images (JPEG streams and flate encoded bitmaps) that hold the QR code; QR codes
 * drawn as vector graphics carry no image and are reported as not found.
 */
class SwissQrBillParserService implements SwissQrBillParserInterface
{
    private const MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(private readonly QrBillGeneratorService $validator) {}

    public function parseFile(string $bytes): ParsedQrBill
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw QrBillParseException::because(QrBillParseException::UNSUPPORTED_FILE);
        }

        $images = str_starts_with($bytes, '%PDF') ? $this->pdfImages($bytes) : [$bytes];

        if ($images === []) {
            throw QrBillParseException::because(QrBillParseException::QR_NOT_FOUND);
        }

        foreach ($images as $image) {
            $payload = $this->decodeImage($image);

            if ($payload !== null && str_starts_with(ltrim($payload), 'SPC')) {
                return $this->parsePayload($payload);
            }
        }

        throw QrBillParseException::because(QrBillParseException::QR_NOT_FOUND);
    }

    public function parsePayload(string $payload): ParsedQrBill
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($payload, "\r\n")) ?: [];

        if (count($lines) < 31 || trim($lines[0]) !== 'SPC') {
            throw QrBillParseException::because(QrBillParseException::INVALID_PAYLOAD);
        }

        $line = static fn (int $index): string => trim($lines[$index] ?? '');
        $errors = [];

        if ($line(1) !== '0200') {
            $errors[] = 'unsupported_version';
        }

        if ($line(2) !== '1') {
            $errors[] = 'unsupported_coding';
        }

        if ($line(30) !== 'EPD') {
            $errors[] = 'missing_trailer';
        }

        $iban = strtoupper(str_replace(' ', '', $line(3)));
        $isQrIban = $this->validator->isValidQrIban($iban);
        $referenceType = $line(27);
        $reference = $line(28) !== '' ? strtoupper(str_replace(' ', '', $line(28))) : null;

        if (! in_array($referenceType, ['QRR', 'SCOR', 'NON'], true)) {
            $errors[] = 'invalid_reference_type';
        } else {
            $check = $this->validator->checkReference($iban, $referenceType === 'NON' ? null : $reference);

            if ($check['type'] !== $referenceType) {
                $errors[] = 'invalid_reference';
            }

            array_push($errors, ...$check['errors']);
        }

        $amountText = $line(18);
        $amount = null;

        if ($amountText !== '') {
            if (! preg_match('/^\d{1,9}\.\d{2}$/', $amountText)) {
                $errors[] = 'invalid_amount';
            } else {
                $amount = (float) $amountText;
            }
        }

        $currency = strtoupper($line(19));

        if (! in_array($currency, ['CHF', 'EUR'], true)) {
            $errors[] = 'invalid_currency';
        }

        $creditor = $this->address($lines, 4);

        if ($creditor['name'] === null || $creditor['country'] === null) {
            $errors[] = 'missing_creditor_address';
        }

        $debtor = $line(20) !== '' ? $this->address($lines, 20) : null;
        $billingRaw = $line(31) !== '' ? $line(31) : null;
        $errors = array_values(array_unique($errors));

        return new ParsedQrBill(
            payload: $payload,
            version: $line(1),
            iban: $iban,
            isQrIban: $isQrIban,
            creditor: $creditor,
            amount: $amount,
            currency: $currency,
            debtor: $debtor,
            referenceType: $referenceType,
            reference: $referenceType === 'NON' ? null : $reference,
            message: $line(29) !== '' ? $line(29) : null,
            billingInformationRaw: $billingRaw,
            billingInformation: $billingRaw !== null ? $this->swicoS1($billingRaw) : [],
            valid: $errors === [],
            errors: $errors,
        );
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<string, ?string>
     */
    private function address(array $lines, int $offset): array
    {
        $field = static fn (int $index): ?string => ($value = trim($lines[$offset + $index] ?? '')) !== '' ? $value : null;
        $type = $field(0);

        if ($type === 'K') {
            // Combined address: two free text lines, the second one is "postal code town".
            $postalCode = null;
            $city = $field(3);

            if ($city !== null && preg_match('/^(?<zip>[A-Za-z0-9-]{3,16})\s+(?<city>.+)$/u', $city, $parts)) {
                $postalCode = $parts['zip'];
                $city = $parts['city'];
            }

            return ['address_type' => 'K', 'name' => $field(1), 'street' => $field(2), 'house_number' => null, 'postal_code' => $postalCode, 'city' => $city, 'country' => $field(6)];
        }

        return [
            'address_type' => $type,
            'name' => $field(1),
            'street' => $field(2),
            'house_number' => $field(3),
            'postal_code' => $field(4),
            'city' => $field(5),
            'country' => $field(6),
        ];
    }

    /**
     * Swico S1 billing information: "//S1/10/<invoice no>/11/<yymmdd>/30/<VAT no>/32/<VAT details>/40/<terms>".
     *
     * @return array<string, string>
     */
    private function swicoS1(string $raw): array
    {
        if (! str_starts_with($raw, '//S1/')) {
            return [];
        }

        $parts = explode('/', substr($raw, 5));
        $result = [];

        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $value = $parts[$i + 1];

            match ($parts[$i]) {
                '10' => $result['invoice_number'] = $value,
                '11' => preg_match('/^\d{6}$/', $value) ? $result['invoice_date'] = '20'.substr($value, 0, 2).'-'.substr($value, 2, 2).'-'.substr($value, 4, 2) : null,
                '30' => $result['vat_number'] = $value,
                '32' => $result['vat_details'] = $value,
                '40' => $result['payment_terms'] = $value,
                default => null,
            };
        }

        return $result;
    }

    /**
     * The ZXing port is sensitive to image size and needs a quiet zone: after the original, padded copies at several
     * widths are tried (a Swiss QR code image has no margin of its own).
     */
    private function decodeImage(string $bytes): ?string
    {
        $text = $this->read($bytes);

        if ($text !== null) {
            return $text;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        $padding = (int) round(max(imagesx($source), imagesy($source)) * 0.12);
        $padded = imagecreatetruecolor(imagesx($source) + 2 * $padding, imagesy($source) + 2 * $padding);

        if ($padded === false) {
            return null;
        }

        imagefill($padded, 0, 0, 0xFFFFFF);
        imagecopy($padded, $source, $padding, $padding, 0, 0, imagesx($source), imagesy($source));

        foreach ([450, 350, 600, 300, 800, 250] as $width) {
            $scaled = $width < imagesx($padded) ? imagescale($padded, $width, -1, IMG_BILINEAR_FIXED) : $padded;

            if ($scaled === false) {
                continue;
            }

            ob_start();
            imagepng($scaled);
            $text = $this->read((string) ob_get_clean());

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function read(string $png): ?string
    {
        try {
            $text = (new QrReader($png, QrReader::SOURCE_TYPE_BLOB))->text();
        } catch (Throwable) {
            return null;
        }

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * Raster images embedded in a PDF, largest first (the QR code is a square bitmap, usually the only image).
     *
     * @return array<int, string>
     */
    private function pdfImages(string $pdf): array
    {
        $images = [];

        // DCT (JPEG) streams are stored as plain JPEG files inside the PDF.
        if (preg_match_all('/\xFF\xD8\xFF.*?\xFF\xD9/s', $pdf, $matches)) {
            foreach ($matches[0] as $jpeg) {
                $images[] = $jpeg;
            }
        }

        try {
            $document = (new PdfParser)->parseContent($pdf);

            foreach ($document->getObjects() as $object) {
                $header = $object->getHeader();

                if ((string) $header->get('Subtype') !== 'Image') {
                    continue;
                }

                $png = $this->flateImageToPng($object, $header);

                if ($png !== null) {
                    $images[] = $png;
                }
            }
        } catch (Throwable) {
            // An unreadable PDF structure still leaves the JPEG streams found above.
        }

        usort($images, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $images;
    }

    /**
     * Reverse the PNG row filters (None, Sub, Up, Average, Paeth) of a decoded image stream.
     */
    private function unfilterPngRows(string $data, int $height, int $rowBytes, int $bpp): string
    {
        $out = '';
        $previous = array_fill(0, $rowBytes, 0);

        for ($y = 0; $y < $height; $y++) {
            $offset = $y * ($rowBytes + 1);
            $filter = ord($data[$offset]);
            $row = [];

            for ($i = 0; $i < $rowBytes; $i++) {
                $value = ord($data[$offset + 1 + $i]);
                $left = $i >= $bpp ? $row[$i - $bpp] : 0;
                $up = $previous[$i];
                $upLeft = $i >= $bpp ? $previous[$i - $bpp] : 0;

                $row[$i] = ($value + match ($filter) {
                    1 => $left,
                    2 => $up,
                    3 => intdiv($left + $up, 2),
                    4 => $this->paeth($left, $up, $upLeft),
                    default => 0,
                }) & 0xFF;
            }

            $out .= pack('C*', ...$row);
            $previous = $row;
        }

        return $out;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
    }

    /**
     * Rebuild a bitmap from a flate encoded 8 bit DeviceGray/DeviceRGB image stream.
     */
    private function flateImageToPng(object $object, object $header): ?string
    {
        $width = (int) (string) $header->get('Width');
        $height = (int) (string) $header->get('Height');
        $bits = (int) (string) $header->get('BitsPerComponent');
        $colorSpace = (string) $header->get('ColorSpace');
        $filter = (string) $header->get('Filter');

        if ($width < 20 || $height < 20 || $width * $height > 16_000_000 || ! str_contains($filter, 'Flate')) {
            return null;
        }

        $raw = $object->getContent();
        $channels = str_contains($colorSpace, 'RGB') ? 3 : 1;

        if (! is_string($raw) || $bits !== 8) {
            return null;
        }

        $rowBytes = $width * $channels;

        // PNG predictors (FPDF and most generators) prefix every row with a filter byte.
        if (strlen($raw) === $height * ($rowBytes + 1)) {
            $raw = $this->unfilterPngRows($raw, $height, $rowBytes, $channels);
        }

        if (strlen($raw) < $height * $rowBytes) {
            return null;
        }

        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            return null;
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $offset = ($y * $width + $x) * $channels;
                $r = ord($raw[$offset]);
                $g = $channels === 3 ? ord($raw[$offset + 1]) : $r;
                $b = $channels === 3 ? ord($raw[$offset + 2]) : $r;
                imagesetpixel($canvas, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }
}
