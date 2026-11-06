<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

/**
 * Company settings of the Swiss payroll, with the defaults of the desktop SwissPayrollSettings.
 */
final readonly class SwissPayrollSettingsData
{
    public function __construct(
        public string $ahvEmployeeRatePercent = '5.3',
        public string $ahvEmployerRatePercent = '5.3',
        public string $ahvAdministrationCostPercent = '0',
        public string $alvEmployeeRatePercent = '1.1',
        public string $alvEmployerRatePercent = '1.1',
        public string $alvAnnualCeiling = '148200',
        public string $uvgAnnualCeiling = '148200',
        public string $nbuEmployeeRatePercent = '0',
        public string $buEmployerRatePercent = '0',
        public string $ktgEmployeeRatePercent = '0',
        public string $ktgEmployerRatePercent = '0',
        public string $fakEmployerRatePercent = '0',
        public string $bvgEmployerSharePercent = '50',
        public string $bvgRiskRatePercent = '0',
        public string $pensionerAllowanceMonthly = '1400',
        public string $defaultWorkCanton = 'TI',
    ) {}
}
