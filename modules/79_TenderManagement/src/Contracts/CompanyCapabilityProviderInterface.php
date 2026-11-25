<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Contracts;

use Carbon\CarbonImmutable;
use Modules\TenderManagement\Domain\ValueObjects\CompanyProfile;
use Modules\TenderManagement\Models\TenderNotice;

/**
 * Reads the track record the tender requirements are compared with (turnover, similar projects) from the ERP.
 */
interface CompanyCapabilityProviderInterface
{
    public function profileFor(TenderNotice $notice, CarbonImmutable $today): CompanyProfile;
}
