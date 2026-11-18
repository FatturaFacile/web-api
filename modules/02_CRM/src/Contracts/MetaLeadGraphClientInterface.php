<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Modules\CRM\Models\LeadSource;

/**
 * Fetches the full lead (field_data) of a Meta Lead Ads leadgen_id from the Graph API.
 */
interface MetaLeadGraphClientInterface
{
    /**
     * @return array<string, mixed>|null the Graph API lead object (with field_data) or null when unavailable
     */
    public function fetchLead(LeadSource $source, string $leadgenId): ?array;
}
