<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Handlers;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\ItalianPayroll\Application\Commands\ImportCedoliniPdfBundleCommand;
use Modules\ItalianPayroll\Domain\Enums\PeriodStatus;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\ItalianPayroll\Infrastructure\Models\EmployeePayslipDistribution;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\ItalianPayroll\Infrastructure\Models\PayrollDocument;
use Modules\ItalianPayroll\Infrastructure\Pdf\CedolinoBundleSplitter;
use Modules\ItalianPayroll\Infrastructure\Pdf\CodiceFiscale;

/**
 * Splits the bulk PDF of the consultant by codice fiscale, encrypts each payslip on the private disk and links it to the
 * employee's distribution. Pages whose code matches no employee are reported, never dropped silently. Importing again
 * replaces the earlier file of the same employee.
 */
final class ImportCedoliniPdfBundleHandler
{
    public const string Disk = 'local';

    public function __construct(private readonly CedolinoBundleSplitter $splitter) {}

    /**
     * @return array{matched: list<array{employee_id: string, tax_code_suffix: string, pages: string}>, unmatched: list<array{tax_code_suffix: string|null, pages: string}>, status: string}
     */
    public function handle(ImportCedoliniPdfBundleCommand $command): array
    {
        $period = ItalianPayrollPeriod::query()->findOrFail($command->periodId);

        if ($period->status->rank() < PeriodStatus::ExportedToCdl->rank() || $period->status === PeriodStatus::Paid) {
            throw new ItalianPayrollException('I cedolini si importano dopo l\'esportazione al consulente e prima del pagamento.');
        }

        $employees = [];

        foreach (Employee::query()->when($period->legal_entity_id !== null, fn ($query) => $query->where('company_id', $period->legal_entity_id))->get() as $employee) {
            if (is_string($employee->tax_code) && $employee->tax_code !== '') {
                $employees[CodiceFiscale::normalize($employee->tax_code)] = (string) $employee->id;
            }
        }

        $matched = [];
        $unmatched = [];
        $stored = [];

        foreach ($this->splitter->split($command->pdfBinary) as $group) {
            $pages = $group['first_page'] === $group['last_page'] ? (string) $group['first_page'] : "{$group['first_page']}-{$group['last_page']}";
            $employeeId = $group['tax_code'] !== null ? ($employees[$group['tax_code']] ?? null) : null;
            $suffix = $group['tax_code'] !== null ? substr($group['tax_code'], -4) : null;

            if ($employeeId === null) {
                $unmatched[] = ['tax_code_suffix' => $suffix, 'pages' => $pages];

                continue;
            }

            $path = "italian-payroll/{$period->tenant_id}/{$period->id}/".Str::uuid7().'.pdf.enc';
            Storage::disk(self::Disk)->put($path, Crypt::encryptString($group['pdf']));
            $stored[] = ['employee_id' => $employeeId, 'path' => $path, 'sha256' => hash('sha256', $group['pdf']), 'size' => strlen($group['pdf'])];
            $matched[] = ['employee_id' => $employeeId, 'tax_code_suffix' => (string) $suffix, 'pages' => $pages];
        }

        try {
            DB::transaction(function () use ($stored, $period, $command): void {
                foreach ($stored as $file) {
                    $document = PayrollDocument::query()->create([
                        'kind' => 'cedolino', 'disk' => self::Disk, 'path' => $file['path'], 'sha256' => $file['sha256'], 'size_bytes' => $file['size'],
                    ]);
                    $distribution = EmployeePayslipDistribution::query()->firstOrNew(['payroll_period_id' => $period->id, 'employee_id' => $file['employee_id']]);
                    $this->discard($distribution->cedolino_pdf_document_id);
                    $distribution->cedolino_pdf_document_id = (string) $document->id;
                    $distribution->save();
                }

                $locked = ItalianPayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);

                if ($stored !== [] && $locked->status === PeriodStatus::ExportedToCdl) {
                    $locked->advanceTo(PeriodStatus::CedoliniImported, $command->actorId);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk(self::Disk)->delete(array_column($stored, 'path'));

            throw $exception;
        }

        return ['matched' => $matched, 'unmatched' => $unmatched, 'status' => $period->refresh()->status->value];
    }

    private function discard(?string $documentId): void
    {
        $previous = $documentId === null ? null : PayrollDocument::query()->find($documentId);

        if ($previous !== null) {
            Storage::disk($previous->disk)->delete($previous->path);
            $previous->delete();
        }
    }
}
