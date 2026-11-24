<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Pdfa;

/**
 * Minimal, dependency-free PDF writer: one page of Helvetica text, optional XMP metadata with PDF/A identification, a
 * `/GTS_PDFA1` output intent and an embedded payload stream. It is used for the conformity certificate and by the
 * simulated test driver; it does not claim real PDF/A validation (no embedded ICC profile).
 */
final class SimplePdfWriter
{
    /**
     * @param  list<string>  $lines
     * @param  array{part: int, conformance: string}|null  $pdfa  PDF/A identification written to the XMP packet
     */
    public static function build(string $title, array $lines, ?array $pdfa = null, ?string $payload = null): string
    {
        $text = "BT\n/F1 11 Tf\n14 TL\n50 790 Td\n";
        foreach (array_slice($lines, 0, 50) as $line) {
            $text .= '('.self::escape(mb_substr($line, 0, 95)).") Tj T*\n";
        }
        $text .= 'ET';

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R'.($pdfa !== null ? ' /Metadata 6 0 R /OutputIntents [7 0 R]' : '').' >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => self::stream($text),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        if ($pdfa !== null) {
            $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
                .'<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
                .'<pdfaid:part>'.$pdfa['part'].'</pdfaid:part><pdfaid:conformance>'.$pdfa['conformance'].'</pdfaid:conformance>'
                .'<dc:title>'.htmlspecialchars($title, ENT_XML1).'</dc:title></rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
            $objects[6] = '<< /Type /Metadata /Subtype /XML /Length '.strlen($xmp)." >>\nstream\n{$xmp}\nendstream";
            $objects[7] = '<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB) /Info (sRGB IEC61966-2.1) >>';
        }

        if ($payload !== null) {
            $objects[8] = '<< /Type /EmbeddedFile /Length '.strlen($payload)." >>\nstream\n{$payload}\nendstream";
        }

        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $max = max(array_keys($objects));
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";

        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }

        return $pdf."trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private static function stream(string $content): string
    {
        return '<< /Length '.strlen($content).' >>'."\nstream\n{$content}\nendstream";
    }

    private static function escape(string $text): string
    {
        $latin = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $latin);
    }
}
