<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Modules\Documents\Exceptions\DuplicateDocumentException;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Services\DocumentLibrary;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Services\JournalBookExporter;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Services\PayrollDocumentService;

/**
 * Files the ledger book of a fiscal year and the payslips of a month in the document library, so they can be added to a
 * preservation package without a manual upload. It reuses the existing generators: the libro giornale CSV of the General
 * Ledger (the same bytes as its download endpoint) and the payslip PDF of Payroll. It invents no format.
 *
 * Not covered, because the modules have no suitable export of their own: the other ledgers (general ledger, VAT
 * registers) and the labour book (LUL) as documents of a preservation class. Payslips are taken from closed (final)
 * payroll runs only. Sealing is idempotent: the same ledger bytes give the same library document (hash), and a payslip
 * already filed is not rendered again.
 */
final readonly class PreservationSealService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private TenantContext $tenantContext,
        private DocumentLibrary $library,
        private JournalBookExporter $journalBook,
        private PayrollDocumentService $payroll,
    ) {}

    /**
     * File the libro giornale of a fiscal year in the library.
     *
     * @return array{document: DmsDocument, reused: bool}
     */
    public function journalBook(FiscalYear $fiscalYear, ?string $from, ?string $to, ?string $folderId, User $user): array
    {
        $tenant = $this->tenant();
        $content = $this->journalBook->csv((string) $tenant->id, $fiscalYear, $from, $to);

        return $this->file($content, $this->journalBook->fileName($fiscalYear), 'text/csv', 'ledger_book', $folderId, $user, [
            'document_date' => Carbon::parse((string) $fiscalYear->end_date)->toDateString(),
            'document_number' => (string) ($fiscalYear->year_code ?: $fiscalYear->id),
            'counterpart_name' => $tenant->legal_name ?: $tenant->name,
            'counterpart_vat_number' => $tenant->vat_number,
            'sealed_source' => "general_ledger_journal_book:{$fiscalYear->id}:{$from}:{$to}",
        ]);
    }

    /**
     * File the payslips of the closed payroll runs of a month in the library, one PDF each.
     *
     * @return list<array{document: DmsDocument, reused: bool, payslip_id: string}>
     *
     * @throws DocumentTrustException when the month has no payslip of a closed run
     */
    public function payslips(int $year, int $month, ?string $folderId, User $user): array
    {
        $tenant = $this->tenant();
        $payslips = Payslip::with('run')->where('year', $year)->where('month', $month)->orderBy('employee_name')->get()
            ->filter(fn (Payslip $payslip): bool => $payslip->run?->isFinal() === true);

        if ($payslips->isEmpty()) {
            throw new DocumentTrustException('There is no payslip of a closed payroll run for this month.');
        }

        $results = [];

        foreach ($payslips as $payslip) {
            $source = "payroll_payslip:{$payslip->id}";
            $existing = DmsDocument::query()->whereRaw("metadata ->> 'sealed_source' = ?", [$source])->first();

            if ($existing !== null) {
                $results[] = ['document' => $existing, 'reused' => true, 'payslip_id' => (string) $payslip->id];

                continue;
            }

            $name = sprintf('Busta_paga_%d-%02d_%s.pdf', $year, $month, preg_replace('/[^A-Za-z0-9]+/', '_', (string) $payslip->employee_name));
            $filed = $this->file($this->payroll->payslip($payslip, $tenant), $name, 'application/pdf', 'payslip', $folderId, $user, [
                'document_date' => Carbon::parse((string) ($payslip->run->payment_date ?? Carbon::create($year, $month, 1)->endOfMonth()))->toDateString(),
                'document_number' => sprintf('%d-%02d-%s', $year, $month, substr((string) $payslip->id, 0, 8)),
                'counterpart_name' => (string) $payslip->employee_name,
                'counterpart_vat_number' => null,
                'sealed_source' => $source,
            ]);
            $results[] = [...$filed, 'payslip_id' => (string) $payslip->id];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{document: DmsDocument, reused: bool}
     */
    private function file(string $content, string $fileName, string $mime, string $class, ?string $folderId, User $user, array $metadata): array
    {
        $path = tempnam(sys_get_temp_dir(), 'seal');
        file_put_contents($path, $content);

        try {
            $document = $this->library->create(
                ['title' => $fileName, 'document_type' => $class, 'folder_id' => $folderId, 'metadata' => [...$metadata, 'sealed_at' => now()->toIso8601String()]],
                new UploadedFile($path, $fileName, $mime, null, true),
                'Filed for preservation',
                false,
                [],
                $user,
            );

            return ['document' => $document, 'reused' => false];
        } catch (DuplicateDocumentException $duplicate) {
            return ['document' => DmsDocument::query()->findOrFail($duplicate->existingDocumentId), 'reused' => true];
        } finally {
            @unlink($path);
        }
    }

    private function tenant(): Tenant
    {
        return Tenant::findOrFail($this->tenantContext->getTenantId());
    }
}
