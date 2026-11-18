<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use App\Models\Contact;
use Modules\CRM\DTOs\DuplicateMatch;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadDuplicateCandidate;

interface LeadDeduplicationServiceInterface
{
    /**
     * Finds leads, contacts and accounts of the tenant matching the given identifiers.
     * Exact matches (email, E.164 phone, VAT) come first, then fuzzy company-name matches.
     *
     * @param  array{email?: ?string, phone?: ?string, phone_e164?: ?string, vat_number?: ?string, company_name?: ?string, country?: ?string}  $identifiers
     * @return list<DuplicateMatch>
     */
    public function findMatches(string $tenantId, array $identifiers, ?string $excludeLeadId = null): array;

    /**
     * Same as findMatches() using the attributes of an existing lead (the lead itself is excluded).
     *
     * @return list<DuplicateMatch>
     */
    public function findMatchesForLead(Lead $lead): array;

    /**
     * Records pending duplicate candidates for the lead and returns the ones created now.
     *
     * @param  list<DuplicateMatch>  $matches
     * @return list<LeadDuplicateCandidate>
     */
    public function recordCandidates(Lead $lead, array $matches): array;

    /**
     * Existing contact with the same VAT number (preferred) or email as the lead.
     */
    public function findContactForLead(Lead $lead): ?Contact;

    /**
     * Existing account the lead belongs to (matched through a contact's VAT/email or the exact company name).
     */
    public function findAccountForLead(Lead $lead): ?Account;

    /**
     * Normalized company-name similarity: [levenshtein distance, similarity ratio 0..1].
     *
     * @return array{0: int, 1: float}
     */
    public function compareCompanyNames(string $normalizedA, string $normalizedB): array;
}
