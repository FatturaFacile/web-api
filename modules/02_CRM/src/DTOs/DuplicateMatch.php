<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

/**
 * One record (lead, contact or account) that looks like the same person/company as an incoming lead.
 */
final readonly class DuplicateMatch
{
    public function __construct(
        public string $recordType, // lead, contact, account
        public string $recordId,
        public string $matchType, // email, phone, vat, company_fuzzy
        public float $confidence,
    ) {}

    /**
     * Exact identifiers (email, E.164 phone, VAT) are the only matches eligible for automatic merging.
     */
    public function isExact(): bool
    {
        return $this->matchType !== 'company_fuzzy';
    }
}
