<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use App\Models\User;
use App\Services\Privacy\PersonalDataRetention;
use App\Services\Privacy\StatutoryRetentionCatalogue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Enums\PreservationDocumentClass;
use Modules\HumanResources\Exceptions\HumanResourcesException;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeDocument;

/**
 * The personnel file: documents per employee on the private disk, with a retention date after which they are erased
 * (row and file). Documents flagged with health data (fitness certificates, medical documents) are confidential by
 * construction; the employee sees a document only when HR makes it visible to them.
 */
final class EmployeeDocumentService
{
    /**
     * Store an uploaded document of an employee. The file is written before the transaction and removed if it fails.
     *
     * @param  array<string, mixed>  $values  validated metadata
     */
    public function store(Employee $employee, UploadedFile $file, array $values, User $actor): EmployeeDocument
    {
        $disk = self::disk();
        $path = (string) $file->storeAs("tenants/{$employee->tenant_id}/hr/employees/{$employee->id}", Str::uuid().'.'.($file->guessExtension() ?: 'bin'), $disk);

        try {
            return DB::transaction(fn (): EmployeeDocument => EmployeeDocument::create([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'document_type' => $values['document_type'],
                'title' => $values['title'],
                'file_path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'issue_date' => $values['issue_date'] ?? null,
                'expiry_date' => $values['expiry_date'] ?? null,
                'retention_until' => $values['retention_until'] ?? null,
                'contains_health_data' => (bool) ($values['contains_health_data'] ?? false),
                'is_confidential' => (bool) ($values['contains_health_data'] ?? false) || (bool) ($values['is_confidential'] ?? true),
                'visible_to_employee' => (bool) ($values['visible_to_employee'] ?? false),
                'status' => 'valid',
                'uploaded_by' => $actor->id,
            ]));
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /**
     * The stored content of a document.
     */
    public function content(EmployeeDocument $document): string
    {
        $disk = Storage::disk(self::disk());

        if (! $disk->exists((string) $document->file_path)) {
            throw new HumanResourcesException('Il file del documento non è disponibile.');
        }

        return (string) $disk->get((string) $document->file_path);
    }

    /**
     * Remove a document before its retention date (soft delete: the row stays for the audit, the file stays until the
     * retention purge).
     */
    public function delete(EmployeeDocument $document): void
    {
        DB::transaction(static fn () => EmployeeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail()->delete());
    }

    /**
     * Erase the documents of a company whose retention date has passed: file and row are removed for good.
     *
     * @return int the documents erased
     */
    public function purgeExpired(string $tenantId): int
    {
        $erased = 0;

        EmployeeDocument::withoutGlobalScopes()->withTrashed()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('retention_until')
            ->whereDate('retention_until', '<', now()->toDateString())
            ->orderBy('id')
            ->lazyById(200)
            ->each(function (EmployeeDocument $document) use (&$erased): void {
                if (self::isHeldByStatute($document)) {
                    return;
                }

                DB::transaction(static function () use ($document): void {
                    Storage::disk(self::disk())->delete((string) $document->file_path);
                    $document->forceDelete();
                });
                $erased++;
            });

        return $erased;
    }

    /**
     * A payslip is a payroll voucher: it stays while its statutory period, counted from its creation, runs, whatever
     * retention date was set on it. The other types follow the retention date.
     */
    private static function isHeldByStatute(EmployeeDocument $document): bool
    {
        return $document->document_type === PreservationDocumentClass::Payslip->value
            && StatutoryRetentionCatalogue::isUnderRetention(PersonalDataRetention::FiscalRecord, $document->created_at);
    }

    private static function disk(): string
    {
        return (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
    }
}
