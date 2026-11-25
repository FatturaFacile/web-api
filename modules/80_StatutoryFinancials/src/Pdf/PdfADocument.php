<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Pdf;

use DateTimeImmutable;
use DateTimeZone;
use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;

/**
 * Writes an archival PDF (PDF/A-1b or PDF/A-2b) of headings, paragraphs and amount tables.
 *
 * What makes it PDF/A level B: the whole TrueType font is embedded (CIDFontType2, Identity-H, with a ToUnicode map), an sRGB
 * output intent with its ICC profile is attached, the XMP metadata packet carries the pdfaid identification and mirrors the document
 * information dictionary, the file has no encryption, scripts, external references or LZW, and the trailer has a file identifier.
 * Colours are device RGB, covered by the output intent. Run veraPDF on the documents before a legal deposit: the verifier of this
 * module is a structural check.
 */
final class PdfADocument
{
    private const float PageWidth = 595.0;

    private const float PageHeight = 842.0;

    private const float Margin = 50.0;

    /** @var list<string> */
    private array $pages = [];

    private string $content = '';

    private float $y;

    /** @var array<int, true> */
    private array $usedRegular = [];

    /** @var array<int, true> */
    private array $usedBold = [];

    /** @var array<int, string> glyph id => unicode of the first character that used it */
    private array $unicode = [];

    private readonly DateTimeImmutable $created;

    public function __construct(
        private readonly string $profile,
        private readonly string $title,
        private readonly string $author,
        private readonly string $subject,
        private readonly string $language,
        private readonly TtfFont $regular,
        private readonly TtfFont $bold,
        private readonly string $iccProfile,
        ?DateTimeImmutable $created = null,
    ) {
        if (! in_array($profile, ['PDF/A-1b', 'PDF/A-2b'], true)) {
            throw new StatutoryFinancialsException('pdfa_profile_unsupported', 422, ['profile' => $profile]);
        }

        $this->created = ($created ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $this->y = self::PageHeight - self::Margin;
    }

    public function heading(string $text, int $level = 1): self
    {
        $size = [1 => 18.0, 2 => 13.0, 3 => 11.0][$level] ?? 11.0;
        $this->ensureSpace($size * 2.2);
        $this->y -= $level === 1 ? 6 : 10;
        $this->text($text, self::Margin, $size, true);
        $this->y -= $size * 1.4;

        return $this;
    }

    public function paragraph(string $text, float $size = 9.5): self
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            foreach ($this->wrap($paragraph, $size, self::PageWidth - 2 * self::Margin) as $line) {
                $this->ensureSpace($size * 1.5);
                $this->text($line, self::Margin, $size, false);
                $this->y -= $size * 1.45;
            }
        }

        $this->y -= 4;

        return $this;
    }

    /**
     * A table row: a label and right-aligned amount columns.
     *
     * @param  list<string>  $amounts
     */
    public function row(string $label, array $amounts, bool $emphasis = false, int $indent = 0): self
    {
        $size = 9.0;
        $this->ensureSpace($size * 1.6);
        $columns = max(1, count($amounts));
        $columnWidth = 82.0;
        $labelWidth = self::PageWidth - 2 * self::Margin - $columns * $columnWidth - 8 * $indent;
        $lines = $this->wrap($label, $size, $labelWidth, $emphasis);
        $first = true;

        foreach ($lines as $line) {
            $this->ensureSpace($size * 1.5);
            $this->text($line, self::Margin + 8 * $indent, $size, $emphasis);

            if ($first) {
                foreach ($amounts as $index => $amount) {
                    $x = self::PageWidth - self::Margin - ($columns - $index - 1) * $columnWidth;
                    $this->text($amount, $x - $this->font($emphasis)->textWidth($amount, $size), $size, $emphasis);
                }

                $first = false;
            }

            $this->y -= $size * 1.4;
        }

        $this->rule();

        return $this;
    }

    public function spacer(float $height = 8.0): self
    {
        $this->y -= $height;

        return $this;
    }

    public function pageBreak(): self
    {
        $this->flushPage();

        return $this;
    }

    public function render(): string
    {
        $this->flushPage();

        $objects = [];
        $next = 7;
        $fonts = [];

        foreach (['F1' => [$this->regular, $this->usedRegular, 'DejaVuSans'], 'F2' => [$this->bold, $this->usedBold, 'DejaVuSans-Bold']] as $resource => [$font, $used, $name]) {
            $ids = ['type0' => $next++, 'cid' => $next++, 'descriptor' => $next++, 'file' => $next++, 'unicode' => $next++];
            $fonts[$resource] = $ids['type0'];
            $glyphs = array_keys($used);
            sort($glyphs);
            $widths = '';

            foreach ($glyphs as $glyph) {
                $widths .= $glyph.' ['.$font->width($glyph).'] ';
            }

            $objects[$ids['type0']] = '<< /Type /Font /Subtype /Type0 /BaseFont /'.$name.' /Encoding /Identity-H /DescendantFonts ['.$ids['cid'].' 0 R] /ToUnicode '.$ids['unicode'].' 0 R >>';
            $objects[$ids['cid']] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /'.$name.' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor '.$ids['descriptor'].' 0 R /DW 1000 /W ['.$widths.'] /CIDToGIDMap /Identity >>';
            $objects[$ids['descriptor']] = '<< /Type /FontDescriptor /FontName /'.$name.' /Flags 32 /FontBBox ['.implode(' ', $font->bbox).'] /ItalicAngle 0 /Ascent '.$font->ascent.' /Descent '.$font->descent.' /CapHeight '.$font->capHeight.' /StemV 80 /FontFile2 '.$ids['file'].' 0 R >>';
            $objects[$ids['file']] = $this->stream('/Length1 '.strlen($font->data), $font->data, true);
            $objects[$ids['unicode']] = $this->stream('', $this->toUnicode($glyphs), true);
        }

        $pageIds = [];
        $contentIds = [];

        foreach ($this->pages as $content) {
            $contentIds[] = $next++;
            $pageIds[] = $next++;
            $objects[$contentIds[array_key_last($contentIds)]] = $this->stream('', $content, true);
        }

        foreach ($pageIds as $index => $pageId) {
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '.$fonts['F1'].' 0 R /F2 '.$fonts['F2'].' 0 R >> >> /Contents '.$contentIds[$index].' 0 R >>';
        }

        $part = $this->profile === 'PDF/A-1b' ? '1' : '2';
        $version = $part === '1' ? '1.4' : '1.7';
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R /Metadata 4 0 R /OutputIntents [6 0 R] /Lang ('.$this->escape($this->language).') /MarkInfo << /Marked true >> >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $pageIds)).'] /Count '.count($pageIds).' >>';
        $objects[3] = '<< /Title '.$this->utf16($this->title).' /Author '.$this->utf16($this->author).' /Subject '.$this->utf16($this->subject).' /Producer ('.$this->escape('FatturaFacile Statutory Financials').') /Creator ('.$this->escape('FatturaFacile').') /CreationDate ('.$this->pdfDate().') /ModDate ('.$this->pdfDate().') >>';
        $objects[4] = $this->stream('/Type /Metadata /Subtype /XML', $this->xmp($part), false);
        $objects[5] = $this->stream('/N 3', $this->iccProfile, true);
        $objects[6] = '<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /Info (sRGB IEC61966-2.1) /DestOutputProfile 5 0 R >>';

        ksort($objects);
        $out = '%PDF-'.$version."\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($out);
        $size = max(array_keys($objects)) + 1;
        $out .= "xref\n0 ".$size."\n0000000000 65535 f \n";

        for ($id = 1; $id < $size; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $fileId = strtoupper(md5($this->title.$this->created->format('c').strlen($out)));
        $out .= 'trailer'."\n".'<< /Size '.$size.' /Root 1 0 R /Info 3 0 R /ID [<'.$fileId.'> <'.$fileId.'>] >>'."\nstartxref\n".$xref."\n%%EOF\n";

        return $out;
    }

    private function font(bool $bold): TtfFont
    {
        return $bold ? $this->bold : $this->regular;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < self::Margin) {
            $this->flushPage();
        }
    }

    private function flushPage(): void
    {
        if ($this->content !== '' || $this->pages === []) {
            $footer = $this->footer(count($this->pages) + 1);
            $this->pages[] = $this->content.$footer;
        }

        $this->content = '';
        $this->y = self::PageHeight - self::Margin;
    }

    private function footer(int $number): string
    {
        $saved = [$this->content, $this->y];
        $this->content = '';
        $text = $this->title.'  -  '.$this->profile.'  -  '.$number;
        $this->text($text, self::Margin, 7.5, false, 28.0);
        $footer = $this->content;
        [$this->content, $this->y] = $saved;

        return $footer;
    }

    private function rule(): void
    {
        $this->content .= sprintf("0.82 0.82 0.82 RG 0.4 w %.2F %.2F m %.2F %.2F l S\n", self::Margin, $this->y + 2.5, self::PageWidth - self::Margin, $this->y + 2.5);
        $this->y -= 1.5;
    }

    private function text(string $text, float $x, float $size, bool $bold, ?float $y = null): void
    {
        if ($text === '') {
            return;
        }

        $font = $this->font($bold);
        $hex = '';

        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char) ?: 63;
            $glyph = $font->glyphId($code);

            if ($glyph === 0) {
                $glyph = $font->glyphId(63);
            }

            if ($bold) {
                $this->usedBold[$glyph] = true;
            } else {
                $this->usedRegular[$glyph] = true;
            }

            $this->unicode[$glyph] ??= sprintf('%04X', $code > 0xFFFF ? 63 : $code);
            $hex .= sprintf('%04X', $glyph);
        }

        $this->content .= sprintf("BT 0 0 0 rg /%s %.2F Tf %.2F %.2F Td <%s> Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y ?? $this->y, $hex);
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, float $size, float $width, bool $bold = false): array
    {
        $font = $this->font($bold);
        $lines = [];
        $line = '';

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;

            if ($line !== '' && $font->textWidth($candidate, $size) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        $lines[] = $line;

        return $lines;
    }

    /**
     * @param  list<int>  $glyphs
     */
    private function toUnicode(array $glyphs): string
    {
        $map = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";

        foreach (array_chunk($glyphs, 100) as $chunk) {
            $map .= count($chunk)." beginbfchar\n";

            foreach ($chunk as $glyph) {
                $map .= sprintf("<%04X> <%s>\n", $glyph, $this->unicode[$glyph] ?? '003F');
            }

            $map .= "endbfchar\n";
        }

        return $map."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    private function xmp(string $part): string
    {
        $date = $this->created->format('Y-m-d\TH:i:sP');
        $title = htmlspecialchars($this->title, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $author = htmlspecialchars($this->author, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $subject = htmlspecialchars($this->subject, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xpacket begin="'."\xEF\xBB\xBF".'" id="W5M0MpCehiHzreSzNTczkc9d"?>'."\n"
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'."\n"
            .'<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"><pdfaid:part>'.$part.'</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance></rdf:Description>'."\n"
            .'<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:format>application/pdf</dc:format><dc:title><rdf:Alt><rdf:li xml:lang="x-default">'.$title.'</rdf:li></rdf:Alt></dc:title><dc:creator><rdf:Seq><rdf:li>'.$author.'</rdf:li></rdf:Seq></dc:creator><dc:description><rdf:Alt><rdf:li xml:lang="x-default">'.$subject.'</rdf:li></rdf:Alt></dc:description></rdf:Description>'."\n"
            .'<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/"><pdf:Producer>FatturaFacile Statutory Financials</pdf:Producer></rdf:Description>'."\n"
            .'<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/"><xmp:CreatorTool>FatturaFacile</xmp:CreatorTool><xmp:CreateDate>'.$date.'</xmp:CreateDate><xmp:ModifyDate>'.$date.'</xmp:ModifyDate></rdf:Description>'."\n"
            .'</rdf:RDF></x:xmpmeta>'."\n".'<?xpacket end="w"?>';
    }

    private function stream(string $dictionary, string $data, bool $compress): string
    {
        $payload = $compress ? (string) gzcompress($data, 6) : $data;
        $filter = $compress ? ' /Filter /FlateDecode' : '';

        return '<< '.$dictionary.$filter.' /Length '.strlen($payload)." >>\nstream\n".$payload."\nendstream";
    }

    private function pdfDate(): string
    {
        return 'D:'.$this->created->format('YmdHis')."+00'00'";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /**
     * PDF text string as a hexadecimal UTF-16BE string with a byte order mark.
     */
    private function utf16(string $text): string
    {
        return '<'.strtoupper(bin2hex("\xFE\xFF".mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))).'>';
    }
}
