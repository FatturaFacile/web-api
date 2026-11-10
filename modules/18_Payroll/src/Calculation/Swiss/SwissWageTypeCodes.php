<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

/**
 * The wage type codes used by the calculation engine.
 */
final class SwissWageTypeCodes
{
    public const string MonthlySalary = '1000';

    public const string HourlyWage = '1005';

    public const string HolidayCompensation = '1006';

    public const string ThirteenthSalary = '1200';

    public const string UnpaidLeave = '1900';

    public const string ChildAllowance = '3000';

    public const string EducationAllowance = '3010';

    public const string Ahv = '9000';

    public const string Alv = '9010';

    public const string Nbu = '9020';

    public const string Ktg = '9030';

    public const string Bvg = '9040';

    public const string BvgBuyIn = 'BVG_BUYIN';

    public const string WithholdingTax = '9050';

    public const string AhvEmployer = '9500';

    public const string AhvAdministration = '9505';

    public const string AlvEmployer = '9510';

    public const string BuEmployer = '9520';

    public const string KtgEmployer = '9530';

    public const string BvgEmployer = '9540';

    public const string FakEmployer = '9550';
}
