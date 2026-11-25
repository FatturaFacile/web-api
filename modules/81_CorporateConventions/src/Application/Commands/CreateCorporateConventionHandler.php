<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\ConventionService;

/**
 * Creates a draft convention with its slug, partner customer and discount matrix.
 */
final class CreateCorporateConventionHandler
{
    public function __construct(
        private readonly ConventionService $conventions,
    ) {}

    public function handle(CreateCorporateConventionCommand $command): CorporatePartnerConvention
    {
        return $this->conventions->create($command->data, $command->tenantId, $command->userId);
    }
}
