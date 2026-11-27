<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Domain\Enums;

/**
 * Passive: we send our material to a vendor (terzista) that processes it. Active: a customer sends us its material that
 * we process for a fee (conto lavoro attivo / Lohnfertigung / verlängerte Werkbank).
 */
enum Direction: string
{
    case Passive = 'passive';
    case Active = 'active';

    /** The partner is a Procurement supplier (passive) or a CRM contact (active). */
    public function partnerType(): string
    {
        return $this === self::Passive ? 'supplier' : 'contact';
    }

    /** Active: the goods belong to the customer, so they carry no value on our balance sheet. */
    public function isCustomerOwned(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
