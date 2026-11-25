<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Pdf;

use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;

/**
 * Minimal TrueType reader: the metrics and the character map (format 4) a PDF/A writer needs to embed the whole font file and to
 * address its glyphs (CIDFontType2 with Identity-H encoding).
 */
final class TtfFont
{
    public int $unitsPerEm = 1000;

    public int $ascent = 800;

    public int $descent = -200;

    public int $capHeight = 700;

    /** @var array{0: int, 1: int, 2: int, 3: int} */
    public array $bbox = [0, 0, 1000, 1000];

    /** @var array<int, int> */
    private array $advance = [];

    private int $numberOfHMetrics = 0;

    /** @var list<array{0: int, 1: int, 2: int, 3: int}> start, end, delta, rangeOffset-position */
    private array $segments = [];

    private function __construct(public readonly string $data, public readonly string $name) {}

    public static function load(string $path, string $name): self
    {
        $data = @file_get_contents($path);

        if ($data === false || strlen($data) < 12) {
            throw new StatutoryFinancialsException('pdfa_font_missing', 500, ['path' => basename($path)]);
        }

        $font = new self($data, $name);
        $font->parse();

        return $font;
    }

    public function glyphId(int $codePoint): int
    {
        foreach ($this->segments as [$start, $end, $delta, $rangePosition]) {
            if ($codePoint < $start || $codePoint > $end) {
                continue;
            }

            $rangeOffset = $this->u16($rangePosition);

            if ($rangeOffset === 0) {
                return ($codePoint + $delta) & 0xFFFF;
            }

            $glyph = $this->u16($rangePosition + $rangeOffset + 2 * ($codePoint - $start));

            return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
        }

        return 0;
    }

    /**
     * Advance width of a glyph in 1/1000 em.
     */
    public function width(int $glyph): int
    {
        $index = min($glyph, max(0, $this->numberOfHMetrics - 1));

        return (int) round(($this->advance[$index] ?? 0) * 1000 / $this->unitsPerEm);
    }

    public function textWidth(string $text, float $size): float
    {
        $sum = 0;

        foreach (mb_str_split($text) as $char) {
            $sum += $this->width($this->glyphId(mb_ord($char) ?: 63));
        }

        return $sum * $size / 1000;
    }

    private function parse(): void
    {
        $tables = [];
        $count = $this->u16(4);

        for ($i = 0; $i < $count; $i++) {
            $entry = 12 + 16 * $i;
            $tables[substr($this->data, $entry, 4)] = $this->u32($entry + 8);
        }

        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $required) {
            if (! isset($tables[$required])) {
                throw new StatutoryFinancialsException('pdfa_font_invalid', 500, ['table' => $required]);
            }
        }

        $head = $tables['head'];
        $this->unitsPerEm = max(1, $this->u16($head + 18));
        $scale = 1000 / $this->unitsPerEm;
        $this->bbox = [(int) round($this->i16($head + 36) * $scale), (int) round($this->i16($head + 38) * $scale), (int) round($this->i16($head + 40) * $scale), (int) round($this->i16($head + 42) * $scale)];

        $hhea = $tables['hhea'];
        $this->ascent = (int) round($this->i16($hhea + 4) * $scale);
        $this->descent = (int) round($this->i16($hhea + 6) * $scale);
        $this->numberOfHMetrics = $this->u16($hhea + 34);
        $this->capHeight = (int) round($this->ascent * 0.72);

        if (isset($tables['OS/2'])) {
            $cap = $this->i16($tables['OS/2'] + 88);
            $this->capHeight = $cap > 0 ? (int) round($cap * $scale) : $this->capHeight;
        }

        $hmtx = $tables['hmtx'];

        for ($i = 0; $i < $this->numberOfHMetrics; $i++) {
            $this->advance[$i] = $this->u16($hmtx + 4 * $i);
        }

        $this->parseCmap($tables['cmap']);
    }

    private function parseCmap(int $cmap): void
    {
        $tables = $this->u16($cmap + 2);
        $subtable = null;

        for ($i = 0; $i < $tables; $i++) {
            $record = $cmap + 4 + 8 * $i;
            $platform = $this->u16($record);
            $encoding = $this->u16($record + 2);

            if (($platform === 3 && $encoding === 1) || ($platform === 0)) {
                $subtable = $cmap + $this->u32($record + 4);

                if ($platform === 3) {
                    break;
                }
            }
        }

        if ($subtable === null || $this->u16($subtable) !== 4) {
            throw new StatutoryFinancialsException('pdfa_font_invalid', 500, ['table' => 'cmap']);
        }

        $segCount = intdiv($this->u16($subtable + 6), 2);
        $endCodes = $subtable + 14;
        $startCodes = $endCodes + 2 * $segCount + 2;
        $deltas = $startCodes + 2 * $segCount;
        $rangeOffsets = $deltas + 2 * $segCount;

        for ($i = 0; $i < $segCount; $i++) {
            $this->segments[] = [$this->u16($startCodes + 2 * $i), $this->u16($endCodes + 2 * $i), $this->i16($deltas + 2 * $i), $rangeOffsets + 2 * $i];
        }
    }

    private function u16(int $offset): int
    {
        return (ord($this->data[$offset] ?? "\0") << 8) | ord($this->data[$offset + 1] ?? "\0");
    }

    private function i16(int $offset): int
    {
        $value = $this->u16($offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(int $offset): int
    {
        return ($this->u16($offset) << 16) | $this->u16($offset + 2);
    }
}
