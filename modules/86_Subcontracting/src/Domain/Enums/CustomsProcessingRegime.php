<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Domain\Enums;

/**
 * Cross-border IT/CH processing: TPP (perfezionamento passivo / passive Veredelung) sends our goods abroad for processing and
 * re-imports them, TPA (perfezionamento attivo / aktive Veredelung) processes foreign goods here and re-exports them.
 */
enum CustomsProcessingRegime: string
{
    case None = 'none';
    case TppOutwardProcessing = 'tpp_outward_processing';
    case TpaInwardProcessing = 'tpa_inward_processing';

    public function isCrossBorder(): bool
    {
        return $this !== self::None;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
