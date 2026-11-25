<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\ConventionService;

/**
 * Updates a convention and, when given, replaces its discount matrix.
 */
final class UpdateCorporateConventionHandler
{
    public function __construct(
        private readonly ConventionService $conventions,
    ) {}

    public function handle(UpdateCorporateConventionCommand $command): CorporatePartnerConvention
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($command->conventionId);

        return $this->conventions->update($convention, $command->data, $command->userId);
    }
}
