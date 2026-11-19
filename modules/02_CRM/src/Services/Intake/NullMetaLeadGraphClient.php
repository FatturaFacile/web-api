<?php

declare(strict_types=1);

namespace Modules\CRM\Services\Intake;

use Modules\CRM\Contracts\MetaLeadGraphClientInterface;
use Modules\CRM\Models\LeadSource;

/**
 * Default Graph client: direct Graph API fetching (page access token per source) is not implemented yet,
 * so bare leadgen notifications yield no fields and are rejected with 422.
 */
final class NullMetaLeadGraphClient implements MetaLeadGraphClientInterface
{
    public function fetchLead(LeadSource $source, string $leadgenId): ?array
    {
        return null;
    }
}
