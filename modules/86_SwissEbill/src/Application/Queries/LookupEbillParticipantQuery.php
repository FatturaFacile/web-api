<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Queries;

use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;

/**
 * Asks the participant directory for one identifier (UID, email, IBAN or participant id), without touching a customer.
 */
final readonly class LookupEbillParticipantQuery
{
    public function __construct(public ParticipantLookupCriteria $criteria, public bool $forceRefresh = false) {}
}
