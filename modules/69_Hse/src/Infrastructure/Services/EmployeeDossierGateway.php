<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Hse\Domain\Contracts\EmployeeDossierGatewayInterface;
use Modules\HumanResources\Models\EmployeeDocument;

/**
 * Employee Dossier (Mod 068) adapter: the document goes to the private disk under the personnel file of the employee.
 * Documents with health data are confidential and invisible to the employee until HR shares them.
 */
final class EmployeeDossierGateway implements EmployeeDossierGatewayInterface
{
    public function archive(string $tenantId, string $employeeId, string $documentType, string $title, string $binary, string $mimeType, bool $containsHealthData = false, ?string $userId = null): string
    {
        $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
        $extension = $mimeType === 'application/pdf' ? 'pdf' : 'bin';
        $path = "tenants/{$tenantId}/hr/employees/{$employeeId}/".Str::uuid().'.'.$extension;

        Storage::disk($disk)->put($path, $binary);

        try {
            $document = EmployeeDocument::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'document_type' => $documentType,
                'title' => $title,
                'file_path' => $path,
                'original_name' => Str::slug($title).'.'.$extension,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($binary),
                'contains_health_data' => $containsHealthData,
                'is_confidential' => $containsHealthData,
                'visible_to_employee' => ! $containsHealthData,
                'status' => 'valid',
                'uploaded_by' => $userId,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        return (string) $document->id;
    }
}
