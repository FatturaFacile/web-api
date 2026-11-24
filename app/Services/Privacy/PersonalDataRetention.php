<?php

declare(strict_types=1);

namespace App\Services\Privacy;

/**
 * How the personal data of a table are treated when a data subject asks for erasure or a retention period ends.
 */
enum PersonalDataRetention: string
{
    /** Operational data: erased (nulled or redacted) on request and when a retention policy says so. */
    case Erasable = 'erasable';

    /** Accounting, tax and commercial records kept by law (Swiss CO art. 958f, Italian c.c. art. 2220): never deleted, pseudonymised only where the law allows. */
    case FiscalRecord = 'fiscal_record';

    /** Employment and payroll records kept by labour and social security law. Never deleted while the statutory period runs. */
    case StatutoryEmployment = 'statutory_employment';

    /** Evidence and audit trails (consent proof, access logs, ledgers): kept to prove accountability, never rewritten. */
    case Evidence = 'evidence';

    /**
     * True when the data may be erased by an automated policy or request.
     */
    public function isErasable(): bool
    {
        return $this === self::Erasable;
    }
}
