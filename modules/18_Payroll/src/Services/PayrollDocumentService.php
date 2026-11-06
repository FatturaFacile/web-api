<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use App\Contracts\PdfGenerator;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Services\WorkingTimeCalculator;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\Italian\ItalianWageCodes;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\WageTypeKind;
use Modules\Payroll\Exports\ItalianPayrollPeriodData;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;
use Modules\Payroll\Models\SalaryCertificate;

/**
 * The printable documents: payslip, salary certificate and labour book (LUL).
 */
final class PayrollDocumentService
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly WorkingTimeCalculator $workingTime,
    ) {}

    public function payslip(Payslip $payslip, Tenant $tenant): string
    {
        $payslip->loadMissing('lines', 'run', 'employee');
        $profile = PayrollEmployeeProfile::query()->where('employee_id', $payslip->employee_id)->first();

        return $this->pdf->render('payroll::payslip', [
            'company' => self::company($tenant),
            'employee' => self::employee($payslip->employee, $profile),
            'payslip' => $payslip,
            'period' => sprintf('%02d/%d', $payslip->month, $payslip->year),
            'paidDays' => Dec::trimmed((string) $payslip->paid_days, 3),
            'paymentDate' => $payslip->run?->payment_date?->format('d.m.Y'),
            'lines' => $this->payslipLines($payslip, WageTypeKind::Employer, false),
            'employerLines' => $this->payslipLines($payslip, WageTypeKind::Employer, true),
        ]);
    }

    public function salaryCertificate(SalaryCertificate $certificate, Tenant $tenant): string
    {
        $certificate->loadMissing('employee');
        $profile = PayrollEmployeeProfile::query()->where('employee_id', $certificate->employee_id)->first();

        return $this->pdf->render('payroll::salary-certificate', [
            'company' => self::company($tenant),
            'employee' => self::employee($certificate->employee, $profile),
            'certificate' => $certificate,
            'issueDate' => now()->format('d.m.Y'),
        ]);
    }

    public function labourBook(PayrollRun $run, Tenant $tenant): string
    {
        $data = ItalianPayrollPeriodData::load($run->year, $run->month);
        $first = PayrollDates::monthStart($run->year, $run->month)->format('Y-m-d');
        $last = PayrollDates::monthEnd($run->year, $run->month)->format('Y-m-d');
        $payslipRows = [];
        $lineRows = [];
        $attendanceRows = [];

        foreach ($data->payslips as $payslip) {
            $employeeId = $payslip->employee_id;
            $profile = $data->profile($employeeId);
            $payslipRows[] = [
                'employee' => $payslip->employee_name,
                'tax_code' => $data->taxCode($employeeId),
                'contract' => trim($profile?->collective_agreement_code.' '.$profile?->contract_level),
                'days' => Dec::trimmed((string) $payslip->paid_days, 3),
                'gross' => (string) $payslip->gross_salary,
                'inps' => Dec::round($data->deducted(ItalianWageCodes::InpsEmployee, $employeeId)),
                'irpef' => Dec::round($data->deducted(ItalianWageCodes::Irpef, $employeeId)),
                'net' => (string) $payslip->payout_amount,
                'employer_inps' => Dec::round($data->added(ItalianWageCodes::InpsEmployer, $employeeId)),
                'tfr' => Dec::round($data->added(ItalianWageCodes::Tfr, $employeeId)),
            ];

            foreach ($data->lines->where('payslip_id', $payslip->id) as $line) {
                $lineRows[] = [
                    'employee' => $payslip->employee_name,
                    'code' => $line->wage_type_code,
                    'description' => $line->description,
                    'quantity' => Dec::isZero((string) $line->quantity) ? '' : Dec::round((string) $line->quantity),
                    'rate' => Dec::isZero((string) $line->rate) ? '' : Dec::round((string) $line->rate),
                    'amount' => (string) $line->amount,
                ];
            }

            $userId = $data->employee($employeeId)?->user_id;
            $user = $userId === null ? null : User::query()->find($userId);

            if ($user === null) {
                continue;
            }

            foreach ($this->workingTime->evaluate($user, $first, $last)->days as $day) {
                $attendanceRows[] = [
                    'employee' => $payslip->employee_name,
                    'date' => $day->date->format('d.m.Y'),
                    'worked' => self::minutes($day->workedMinutes),
                    'overtime' => $day->balanceMinutes() > 0 ? self::minutes($day->balanceMinutes()) : '',
                    'absence' => $day->absence ?? '',
                ];
            }
        }

        return $this->pdf->render('payroll::labour-book', [
            'company' => self::company($tenant),
            'period' => sprintf('%02d/%d', $run->month, $run->year),
            'inpsCompanyNumber' => $data->settings?->inps_company_number,
            'inailCode' => $data->settings?->inail_code,
            'printDate' => CarbonImmutable::now()->format('d.m.Y'),
            'payslips' => $payslipRows,
            'lines' => $lineRows,
            'attendance' => $attendanceRows,
        ]);
    }

    /**
     * @return array{name: string, uid: ?string}
     */
    private static function company(Tenant $tenant): array
    {
        return [
            'name' => (string) ($tenant->legal_name ?: $tenant->name),
            'uid' => $tenant->uid_number ?? $tenant->vat_number,
        ];
    }

    /**
     * @return array<string, ?string>
     */
    private static function employee(?Employee $employee, ?PayrollEmployeeProfile $profile): array
    {
        return [
            'name' => $employee?->full_name,
            'number' => $employee?->employee_number,
            'address' => trim($profile?->street.' '.$profile?->house_number),
            'city' => trim($profile?->postal_code.' '.$profile?->city),
            'social_security_number' => $profile?->social_security_number ?? $employee?->social_security_number ?? $employee?->tax_code,
            'date_of_birth' => $employee?->birth_date?->format('d.m.Y'),
            'iban' => $profile?->iban,
        ];
    }

    private static function minutes(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @return list<array{code: string, description: string, quantity: string, rate: string, amount: string}>
     */
    private function payslipLines(Payslip $payslip, string $kind, bool $matching): array
    {
        $rows = [];

        foreach ($payslip->lines as $line) {
            if (($line->kind === $kind) !== $matching) {
                continue;
            }

            $rows[] = $this->payslipLineRow($line);
        }

        return $rows;
    }

    /**
     * @return array{code: string, description: string, quantity: string, rate: string, amount: string}
     */
    private function payslipLineRow(PayslipLine $line): array
    {
        $percent = WageTypeKind::isContribution($line->kind);
        $quantity = (string) $line->quantity;
        $rate = (string) $line->rate;
        $hidden = Dec::isZero($quantity) || (Dec::cmp($quantity, '1') === 0 && ! $percent);

        return [
            'code' => $line->wage_type_code,
            'description' => $line->description,
            'quantity' => $hidden ? '' : Dec::round($quantity),
            'rate' => match (true) {
                Dec::isZero($rate) => '',
                $percent => Dec::trimmed($rate).'%',
                default => Dec::round($rate),
            },
            'amount' => (string) $line->amount,
        ];
    }
}
