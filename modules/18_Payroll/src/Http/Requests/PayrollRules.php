<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

/**
 * Validation values shared by the payroll requests, from the desktop PayrollValidation.
 */
final class PayrollRules
{
    /** @var list<string> */
    public const array Cantons = ['AG', 'AI', 'AR', 'BE', 'BL', 'BS', 'FR', 'GE', 'GL', 'GR', 'JU', 'LU', 'NE', 'NW', 'OW', 'SG', 'SH', 'SO', 'SZ', 'TG', 'TI', 'UR', 'VD', 'VS', 'ZG', 'ZH'];

    /** @var list<string> */
    public const array Permits = ['CH', 'C', 'B', 'L', 'G', 'F', 'N', 'S', 'CI', 'Ci', 'Meldeverfahren'];

    /**
     * @return array<string, list<string>>
     */
    public static function period(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
