<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * VAT treatment of the instruction part of a lesson or course (the exemption of sports and educational activities).
 */
enum VatRegime: string
{
    case IT_EXEMPT_ART10_N20 = 'IT_EXEMPT_ART10_N20';
    case CH_EXEMPT_ART21_N11 = 'CH_EXEMPT_ART21_N11';
    case TAXABLE = 'TAXABLE';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isExempt(): bool
    {
        return $this !== self::TAXABLE;
    }

    public function jurisdiction(): string
    {
        return $this === self::CH_EXEMPT_ART21_N11 ? 'CH' : 'IT';
    }

    /** The exemption that applies to sports instruction in the jurisdiction of the school. */
    public static function exemptionFor(string $jurisdiction): self
    {
        return strtoupper($jurisdiction) === 'CH' ? self::CH_EXEMPT_ART21_N11 : self::IT_EXEMPT_ART10_N20;
    }

    /** Legal reference printed on the invoice line. */
    public function legalReference(): ?string
    {
        return $this->isExempt() ? (string) config('ski_school.exemptions.'.$this->jurisdiction().'.legal_reference') : null;
    }

    /** SDI nature code (N4 exempt) for the Italian e-invoice; Swiss invoices have none. */
    public function sdiNature(): ?string
    {
        return $this === self::IT_EXEMPT_ART10_N20 ? (string) config('ski_school.exemptions.IT.nature') : null;
    }
}
