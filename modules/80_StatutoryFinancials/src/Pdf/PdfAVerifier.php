<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Pdf;

/**
 * Structural verification of a PDF/A-1b or PDF/A-2b file: the identification in the XMP metadata, the output intent and ICC profile,
 * the document information dictionary, embedded fonts, the absence of encryption, scripts, launch actions and LZW, the trailer
 * identifier and the integrity of the cross-reference table. It is a conformance screen run on every generated file, not a
 * replacement of a full validator (veraPDF) for a legal deposit.
 */
final class PdfAVerifier
{
    /**
     * @return array{profile: string, compliant: bool, checks: list<array{id: string, passed: bool, detail: string}>, verified_by: string}
     */
    public function verify(string $pdf, string $expectedProfile): array
    {
        $part = $expectedProfile === 'PDF/A-1b' ? '1' : '2';
        $checks = [];
        $check = static function (string $id, bool $passed, string $detail = '') use (&$checks): void {
            $checks[] = ['id' => $id, 'passed' => $passed, 'detail' => $detail];
        };

        $check('header', (bool) preg_match('/^%PDF-1\.[4-7]/', $pdf), 'PDF header version');
        $check('binary_comment', (bool) preg_match('/^%PDF-1\.\d\R%[\x80-\xFF]{4}/', $pdf), 'binary marker after the header');
        $check('eof', (bool) preg_match('/%%EOF\s*$/', $pdf), 'end-of-file marker');

        // Compressed and binary streams are left out of the keyword scans: only the object dictionaries are read.
        $text = preg_replace('#stream\r?\n.*?\r?\nendstream#s', 'stream endstream', $pdf) ?? $pdf;
        $xmp = $this->xmp($pdf);
        $check('xmp_present', $xmp !== null, 'XMP metadata packet');
        $check('xmp_pdfaid_part', $xmp !== null && (bool) preg_match('#<pdfaid:part>'.$part.'</pdfaid:part>#', $xmp), 'pdfaid:part '.$part);
        $check('xmp_pdfaid_conformance', $xmp !== null && (bool) preg_match('#<pdfaid:conformance>B</pdfaid:conformance>#', $xmp), 'pdfaid:conformance B');
        $check('xmp_title', $xmp !== null && (bool) preg_match('#<dc:title>.+?</dc:title>#s', $xmp), 'dc:title');
        $check('xmp_dates', $xmp !== null && str_contains($xmp, '<xmp:CreateDate>') && str_contains($xmp, '<xmp:ModifyDate>'), 'xmp:CreateDate and xmp:ModifyDate');

        $check('output_intent', str_contains($text, '/GTS_PDFA1') && (bool) preg_match('#/DestOutputProfile\s+\d+\s+0\s+R#', $text), 'GTS_PDFA1 output intent with ICC profile');
        $check('info_dictionary', (bool) preg_match('#/Title\s*[<(]#', $text) && str_contains($text, '/CreationDate'), 'document information dictionary');
        $check('trailer_id', (bool) preg_match('#trailer\s*<<.*?/ID\s*\[\s*<[0-9A-Fa-f]+>\s*<[0-9A-Fa-f]+>\s*\]#s', $text), 'file identifier in the trailer');

        $check('no_encryption', ! str_contains($text, '/Encrypt'), 'no encryption');
        $check('no_javascript', ! preg_match('#/(JavaScript|JS)\b#', $text), 'no JavaScript');
        $check('no_launch', ! preg_match('#/(Launch|URI|GoToR)\b#', $text), 'no launch or external references');
        $check('no_lzw', ! str_contains($text, '/LZWDecode'), 'no LZW compression');
        $check('no_embedded_files', ! str_contains($text, '/EmbeddedFile'), 'no embedded files');

        $descriptors = preg_match_all('#/Type\s*/FontDescriptor(.*?)>>#s', $text, $matches);
        $embedded = 0;

        foreach ($matches[1] as $descriptor) {
            if (preg_match('#/FontFile[23]?\s+\d+\s+0\s+R#', $descriptor)) {
                $embedded++;
            }
        }

        $check('fonts_embedded', $descriptors > 0 && $embedded === $descriptors, $embedded.' of '.$descriptors.' fonts embedded');
        $check('xref', $this->xrefIsIntact($pdf), 'cross-reference table');

        $compliant = array_reduce($checks, static fn (bool $carry, array $item): bool => $carry && $item['passed'], true);

        return ['profile' => $expectedProfile, 'compliant' => $compliant, 'checks' => $checks, 'verified_by' => 'structural-screen'];
    }

    private function xmp(string $pdf): ?string
    {
        if (preg_match('#<\?xpacket begin=.*?<\?xpacket end="[wr]"\?>#s', $pdf, $match)) {
            return $match[0];
        }

        return null;
    }

    private function xrefIsIntact(string $pdf): bool
    {
        if (! preg_match('#startxref\s+(\d+)\s+%%EOF#', $pdf, $match)) {
            return false;
        }

        $offset = (int) $match[1];

        if (substr($pdf, $offset, 4) !== 'xref') {
            return false;
        }

        if (! preg_match('#xref\s+0\s+(\d+)\s#', substr($pdf, $offset, 40), $count)) {
            return false;
        }

        // Every in-use entry must point at "N 0 obj".
        $table = substr($pdf, $offset);

        if (! preg_match_all('#(\d{10}) 00000 n #', $table, $entries)) {
            return false;
        }

        foreach ($entries[1] as $index => $position) {
            if (! preg_match('#^'.($index + 1).' 0 obj#', substr($pdf, (int) $position, 20))) {
                return false;
            }
        }

        return count($entries[1]) === (int) $count[1] - 1;
    }
}
