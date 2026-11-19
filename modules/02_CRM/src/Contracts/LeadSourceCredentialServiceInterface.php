<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Modules\CRM\Models\LeadSource;

interface LeadSourceCredentialServiceInterface
{
    /**
     * Generates and stores new credentials (secret encrypted, API key hashed) and returns the plain values,
     * which are shown to the caller once and never again.
     *
     * @param  'both'|'webhook_secret'|'api_key'  $which
     * @return array{webhook_secret?: string, api_key?: string}
     */
    public function rotate(LeadSource $source, string $which = 'both', ?string $actorUserId = null): array;
}
