<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\EnrollmentService;

/**
 * Instant lookup of a pass by QR payload, card, matricola hash or e-mail; enrols the holder of a listed card or hash when a name is given.
 */
final class VerifyBeneficiaryByCardOrHashHandler
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * @return array{enrollment: ConventionBeneficiaryEnrollment, created: bool}
     */
    public function handle(VerifyBeneficiaryByCardOrHashCommand $command): array
    {
        $existing = $this->enrollments->lookup($command->identifier, $command->conventionId);

        if ($existing !== null) {
            $this->enrollments->assertUsable($existing);

            return ['enrollment' => $existing, 'created' => false];
        }

        if ($command->conventionId === null || $command->fullName === null) {
            throw new ConventionException('enrollment_not_found', 404);
        }

        $convention = CorporatePartnerConvention::query()->findOrFail($command->conventionId);

        return ['enrollment' => $this->enrollments->enrollByIdentifier($convention, $command->identifier, $command->fullName, $command->email, $command->userId), 'created' => true];
    }
}
