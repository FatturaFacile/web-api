<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

/**
 * Links a customer to a participant found in the directory (the one-click link of the lookup dialog). The participant is looked up
 * again by its id, so only a participant the directory really knows can be linked.
 */
final readonly class LinkCustomerEbillParticipantCommand
{
    public function __construct(public string $customerId, public string $participantId) {}
}
