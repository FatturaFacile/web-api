<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\ValueObjects;

use Modules\Manufacturing\Exceptions\ManufacturingOperationException;

/**
 * A lot number (Italian: lotto) as scanned or typed on the shop floor: trimmed, upper-cased and limited to the
 * characters of barcodes, so the same lot always reads the same.
 */
final readonly class LotCode
{
    private function __construct(public string $value) {}

    /**
     * Parses a raw lot number, refusing an empty or malformed one.
     */
    public static function fromString(string $raw): self
    {
        $value = strtoupper(trim($raw));
        if (preg_match('/^[A-Z0-9][A-Z0-9._\-\/]{0,63}$/', $value) !== 1) {
            throw new ManufacturingOperationException("[{$raw}] is not a valid lot number.", 'lot_code');
        }

        return new self($value);
    }

    /**
     * The normalised lot number.
     */
    public function toString(): string
    {
        return $this->value;
    }
}
