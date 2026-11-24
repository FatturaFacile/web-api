<?php

declare(strict_types=1);

namespace Modules\Communication\Contracts;

/**
 * Reads the effective consent of a person from the system of record (the CRM) so that the Communication module never
 * keeps a second copy of it.
 */
interface ConsentLookup
{
    /**
     * `granted`, `withdrawn` or null when nothing was ever recorded for the subject, channel and purpose.
     */
    public function status(?string $contactId, ?string $leadId, string $channel, string $purpose): ?string;
}
