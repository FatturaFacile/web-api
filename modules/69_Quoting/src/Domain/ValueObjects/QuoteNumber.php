<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/** {PREFIX}-{YEAR}-{SEQUENCE}.{REVISION}, e.g. OFF-2026-00123.02 (or the letter alias Rev. B). */
final readonly class QuoteNumber implements Stringable
{
    public function __construct(
        public string $prefix,
        public int $year,
        public int $sequence,
        public int $revision = 1,
    ) {
        if ($prefix === '' || preg_match('/^[A-Z0-9]{1,12}$/', $prefix) !== 1) {
            throw new InvalidArgumentException('Quote number prefix must be 1-12 uppercase letters or digits.');
        }

        if ($sequence < 1 || $revision < 1) {
            throw new InvalidArgumentException('Quote sequence and revision start at 1.');
        }
    }

    public static function parse(string $value): self
    {
        if (preg_match('/^([A-Z0-9]{1,12})-(\d{4})-(\d{1,9})\.(\d{2,3})$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("Invalid quote number [{$value}].");
        }

        return new self($m[1], (int) $m[2], (int) $m[3], (int) $m[4]);
    }

    public function nextRevision(): self
    {
        return new self($this->prefix, $this->year, $this->sequence, $this->revision + 1);
    }

    /** Revision 1 = A, 2 = B ... 27 = AA. */
    public function revisionAlias(): string
    {
        $n = $this->revision;
        $alias = '';

        while ($n > 0) {
            $n--;
            $alias = chr(65 + $n % 26).$alias;
            $n = intdiv($n, 26);
        }

        return 'Rev. '.$alias;
    }

    public function __toString(): string
    {
        return sprintf('%s-%04d-%05d.%02d', $this->prefix, $this->year, $this->sequence, $this->revision);
    }
}
