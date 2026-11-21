<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Contracts;

/**
 * Employee Dossier (Mod 068): archives a generated document in the personnel file of the employee.
 */
interface EmployeeDossierGatewayInterface
{
    /**
     * Stores the binary in the personnel file and returns the id of the stored document.
     */
    public function archive(string $tenantId, string $employeeId, string $documentType, string $title, string $binary, string $mimeType, bool $containsHealthData = false, ?string $userId = null): string;
}
