<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\PayslipLineData;
use Modules\Payroll\Calculation\Swiss\SalaryCertificateBoxes;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;
use Modules\Payroll\Models\SalaryCertificate;

/**
 * Generates the annual salary certificates (Form 11 / Lohnausweis) with eCH-0019 PDF417 barcode
 * from the closed runs; generating again replaces those of the year.
 */
final class SalaryCertificateService
{
    public function __construct(
        private readonly LohnausweisBarcodeGenerator $barcodes = new LohnausweisBarcodeGenerator,
        private readonly ?TenantContext $tenantContext = null,
    ) {}

    /**
     * @return int the certificates generated
     */
    public function generate(int $year): int
    {
        $runIds = PayrollRun::query()->where('year', $year)->where('status', PayrollRun::StatusFinal)->pluck('id');

        if ($runIds->isEmpty()) {
            throw new PayrollException("Nessuna elaborazione chiusa nel {$year}.");
        }

        return DB::transaction(function () use ($year, $runIds): int {
            $payslips = Payslip::query()->whereIn('payroll_run_id', $runIds)->get();
            $lines = PayslipLine::query()->whereIn('payslip_id', $payslips->modelKeys())->get()->groupBy('payslip_id');
            $employeeIds = $payslips->pluck('employee_id')->unique()->all();
            $employees = Employee::query()->withTrashed()->whereIn('id', $employeeIds)->get()->keyBy('id');
            $profiles = PayrollEmployeeProfile::query()->whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');
            $tenant = $this->tenantContext?->hasTenant() ? $this->tenantContext->getTenant() : null;
            $yearStart = PayrollDates::monthStart($year, 1);
            $yearEnd = PayrollDates::monthEnd($year, 12);

            SalaryCertificate::query()->where('year', $year)->delete();
            $count = 0;

            foreach ($payslips->groupBy('employee_id') as $employeeId => $group) {
                $employee = $employees->get($employeeId);
                $profile = $profiles->get($employeeId);
                $hire = $employee?->hire_date === null ? null : PayrollDates::from($employee->hire_date);
                $exit = $employee?->termination_date === null ? null : PayrollDates::from($employee->termination_date);
                $certificateLines = [];

                foreach ($group as $payslip) {
                    foreach ($lines->get($payslip->id, []) as $line) {
                        $certificateLines[] = new PayslipLineData($line->wage_type_code, $line->description, $line->kind, (string) $line->quantity, (string) $line->rate, (string) $line->amount, $line->sort_order, $line->certificate_box);
                    }
                }

                $periodFrom = ($hire !== null && $hire > $yearStart ? $hire : $yearStart)->format('Y-m-d');
                $periodTo = ($exit !== null && $exit < $yearEnd ? $exit : $yearEnd)->format('Y-m-d');
                $boxes = SalaryCertificateBoxes::fill($certificateLines, $employee, $profile);
                $barcodePayload = $this->barcodes->generatePayload($year, $periodFrom, $periodTo, $boxes, $employee, $profile, $tenant);
                $barcodeSvg = $this->barcodes->generateSvg($barcodePayload);

                SalaryCertificate::query()->create([
                    'employee_id' => $employeeId,
                    'year' => $year,
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'generated_at' => now(),
                    ...$boxes,
                    'remarks' => $boxes['box15_remarks'],
                    'barcode_payload' => $barcodePayload,
                    'barcode_svg' => $barcodeSvg,
                ]);
                $count++;
            }

            return $count;
        });
    }
}
