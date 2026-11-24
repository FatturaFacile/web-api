<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

/**
 * Codes of the Italian payslip lines.
 */
final class ItalianWageCodes
{
    public const string Salary = 'IT1000';

    public const string Thirteenth = 'IT1013';

    public const string Fourteenth = 'IT1014';

    public const string UnpaidAbsence = 'IT1900';

    public const string InpsEmployee = 'IT5000';

    public const string Irpef = 'IT6000';

    public const string RegionalSurcharge = 'IT6100';

    public const string MunicipalSurcharge = 'IT6200';

    public const string TaxWedge = 'IT7000';

    public const string InpsEmployer = 'IT8000';

    public const string Tfr = 'IT8100';
}
