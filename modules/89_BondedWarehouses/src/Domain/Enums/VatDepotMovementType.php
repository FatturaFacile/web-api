<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum VatDepotMovementType: string
{
    case InboundFreeCirculation = 'Inbound_FreeCirculation';
    case InboundIntraEU = 'Inbound_IntraEU';
    case InternalTransferNoVAT = 'Internal_Transfer_NoVAT';
    case ExtractionTD22 = 'Extraction_TD22';
    case ExtractionTD23 = 'Extraction_TD23';
    case ExtractionLetterOfIntent = 'Extraction_LetterOfIntent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isExtraction(): bool
    {
        return in_array($this, [self::ExtractionTD22, self::ExtractionTD23, self::ExtractionLetterOfIntent], true);
    }
}
