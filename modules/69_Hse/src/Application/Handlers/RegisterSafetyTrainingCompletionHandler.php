<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\Hse\Application\Commands\RegisterSafetyTrainingCompletionCommand;
use Modules\Hse\Domain\Contracts\EmployeeDossierGatewayInterface;
use Modules\Hse\Domain\Enums\CertificateStatus;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Domain\Exceptions\HseNotFoundException;
use Modules\Hse\Infrastructure\Models\EmployeeSafetyCertificate;
use Modules\HumanResources\Models\Employee;

/**
 * Records a completed course or license. Without an explicit expiry the next refresher follows the statutory cycle of
 * the jurisdiction (5 years for the Accordo Stato-Regioni courses, 2 years for first aid and fire in the specific risk
 * classes). A newer certificate supersedes the older ones of the same type: the expiration scan stops alerting on them.
 */
final class RegisterSafetyTrainingCompletionHandler
{
    public function __construct(private readonly EmployeeDossierGatewayInterface $dossier) {}

    public function handle(RegisterSafetyTrainingCompletionCommand $command): EmployeeSafetyCertificate
    {
        Employee::query()->where('tenant_id', $command->tenantId)->whereKey($command->employeeId)->exists() || throw new HseNotFoundException('Unknown employee.');

        if ($command->expiresAt !== null && $command->expiresAt->lt($command->issuedAt)) {
            throw new HseException('A certificate cannot expire before it is issued.');
        }

        $jurisdiction = Jurisdiction::forCountry(Tenant::query()->findOrFail($command->tenantId)->country);
        $expiresAt = $command->expiresAt ?? $command->certificateType->nextRefresher($command->issuedAt, $jurisdiction, $command->specificRiskClass);

        $documentId = null;

        if ($command->certificatePdfBase64 !== null && $command->certificatePdfBase64 !== '') {
            $binary = base64_decode($command->certificatePdfBase64, true);

            if ($binary === false) {
                throw new HseException('The certificate document is not valid base64.');
            }

            $documentId = $this->dossier->archive($command->tenantId, $command->employeeId, 'safety_certificate', 'Safety certificate '.$command->certificateType->value, $binary, 'application/pdf', false, $command->userId);
        }

        return EmployeeSafetyCertificate::create([
            'tenant_id' => $command->tenantId,
            'employee_id' => $command->employeeId,
            'certificate_type' => $command->certificateType,
            'certificate_number' => $command->certificateNumber,
            'training_provider_name' => $command->trainingProviderName,
            'hours_completed' => $command->hoursCompleted,
            'issued_at' => $command->issuedAt->toDateString(),
            'expires_at' => $expiresAt?->toDateString(),
            'document_id' => $documentId,
            'status' => CertificateStatus::at($expiresAt, CarbonImmutable::now()),
        ]);
    }
}
