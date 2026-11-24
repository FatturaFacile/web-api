<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

/**
 * NaturaType of the FatturaPA schema v1.2.3. The parent codes N2, N3 and N6 are still in the schema, which marks them as
 * no longer valid since 1 January 2021: only `issuable()` can be requested on a new document.
 */
enum NaturaIva: string
{
    case N1 = 'N1';
    case N2 = 'N2';
    case N21 = 'N2.1';
    case N22 = 'N2.2';
    case N3 = 'N3';
    case N31 = 'N3.1';
    case N32 = 'N3.2';
    case N33 = 'N3.3';
    case N34 = 'N3.4';
    case N35 = 'N3.5';
    case N36 = 'N3.6';
    case N4 = 'N4';
    case N5 = 'N5';
    case N6 = 'N6';
    case N61 = 'N6.1';
    case N62 = 'N6.2';
    case N63 = 'N6.3';
    case N64 = 'N6.4';
    case N65 = 'N6.5';
    case N66 = 'N6.6';
    case N67 = 'N6.7';
    case N68 = 'N6.8';
    case N69 = 'N6.9';
    case N7 = 'N7';

    /**
     * @return list<string>
     */
    public static function issuable(): array
    {
        return array_values(array_filter(
            array_column(self::cases(), 'value'),
            static fn (string $value): bool => ! in_array($value, ['N2', 'N3', 'N6'], true)
        ));
    }
}
