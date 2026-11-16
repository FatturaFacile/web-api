<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Illuminate\Http\Request;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\Models\LeadSource;

/**
 * Translates a provider-specific webhook delivery (web form, WordPress, Webflow, Meta, LinkedIn) into a lead.
 */
interface LeadPayloadAdapter
{
    /**
     * Checks the authenticity of the delivery (signature, timestamp, API key) for the given source.
     */
    public function verify(Request $request, LeadSource $source): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toDto(array $payload, LeadSource $source): CreateLeadDTO;
}
