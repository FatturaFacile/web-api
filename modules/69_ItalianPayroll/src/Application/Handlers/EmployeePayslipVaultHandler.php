<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Handlers;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\HumanResources\Models\Employee;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\ItalianPayroll\Infrastructure\Models\EmployeePayslipDistribution;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\ItalianPayroll\Infrastructure\Models\PayrollDocument;
use Modules\ItalianPayroll\Infrastructure\Models\PayslipDownloadAudit;

/**
 * The employee's own vault: lists the payslips and CU of the signed-in user and decrypts one on request. Every opening
 * is written to the append-only audit, and the first one also stamps the distribution (the receipt of L. 4/1953).
 */
final class EmployeePayslipVaultHandler
{
    /**
     * @return list<array{distribution_id: string, year: int, month: int, net_amount: string, has_cedolino: bool, has_cu: bool, first_viewed_at: string|null}>
     */
    public function list(string $userId): array
    {
        $employee = $this->employee($userId);

        return EmployeePayslipDistribution::query()->where('employee_id', $employee->id)->get()
            ->map(function (EmployeePayslipDistribution $row): array {
                $period = ItalianPayrollPeriod::query()->find($row->payroll_period_id);

                return [
                    'distribution_id' => (string) $row->id, 'year' => (int) $period?->year, 'month' => (int) $period?->month,
                    'net_amount' => (string) $row->net_amount, 'has_cedolino' => $row->cedolino_pdf_document_id !== null,
                    'has_cu' => $row->cu_pdf_document_id !== null, 'first_viewed_at' => $row->viewed_by_employee_at?->toIso8601String(),
                ];
            })->sortByDesc(fn (array $row): int => $row['year'] * 100 + $row['month'])->values()->all();
    }

    /**
     * @param  'cedolino'|'cu'  $kind
     * @return string the clear PDF
     */
    public function open(string $userId, string $distributionId, string $kind, ?string $ip, ?string $userAgent, bool $biometricConfirmed): string
    {
        $employee = $this->employee($userId);
        $distribution = EmployeePayslipDistribution::query()->where('employee_id', $employee->id)->findOrFail($distributionId);
        $documentId = $kind === 'cu' ? $distribution->cu_pdf_document_id : $distribution->cedolino_pdf_document_id;
        $document = $documentId === null ? null : PayrollDocument::query()->find($documentId);

        if ($document === null) {
            throw new ItalianPayrollException('Documento non disponibile.');
        }

        $pdf = Crypt::decryptString((string) Storage::disk($document->disk)->get($document->path));

        DB::transaction(function () use ($distribution, $employee, $kind, $ip, $userAgent, $biometricConfirmed): void {
            PayslipDownloadAudit::query()->create([
                'distribution_id' => $distribution->id, 'employee_id' => $employee->id, 'document_kind' => $kind, 'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, 'biometric_confirmed' => $biometricConfirmed, 'occurred_at' => now(),
            ]);

            if ($distribution->viewed_by_employee_at === null) {
                $distribution->viewed_by_employee_at = now()->toImmutable();
                $distribution->viewed_from_ip = $ip;
                $distribution->save();
            }
        });

        return $pdf;
    }

    private function employee(string $userId): Employee
    {
        return Employee::query()->where('user_id', $userId)->first() ?? throw new ItalianPayrollException('Nessun dipendente collegato all\'utente.');
    }
}
