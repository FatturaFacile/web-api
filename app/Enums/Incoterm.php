<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Incoterms 2020. The enum states who bears main carriage and insurance, which drives the customs value.
 */
enum Incoterm: string
{
    case Exw = 'EXW';
    case Fca = 'FCA';
    case Cpt = 'CPT';
    case Cip = 'CIP';
    case Dap = 'DAP';
    case Dpu = 'DPU';
    case Ddp = 'DDP';
    case Fas = 'FAS';
    case Fob = 'FOB';
    case Cfr = 'CFR';
    case Cif = 'CIF';

    /**
     * Whether the price of the seller already covers the main carriage to the destination (or port of destination).
     */
    public function sellerPaysFreight(): bool
    {
        return match ($this) {
            self::Cpt, self::Cip, self::Dap, self::Dpu, self::Ddp, self::Cfr, self::Cif => true,
            default => false,
        };
    }

    /**
     * Whether the price of the seller already covers cargo insurance.
     */
    public function sellerPaysInsurance(): bool
    {
        return match ($this) {
            self::Cip, self::Cif, self::Dap, self::Dpu, self::Ddp => true,
            default => false,
        };
    }

    public function isSeaOnly(): bool
    {
        return in_array($this, [self::Fas, self::Fob, self::Cfr, self::Cif], true);
    }

    /**
     * Whether import duties are for the seller (delivered duty paid).
     */
    public function sellerPaysImportDuties(): bool
    {
        return $this === self::Ddp;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
