<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use App\Models\Contact;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Application\Services\ParticipantDirectoryService;
use Modules\SwissEbill\Domain\Exceptions\EbillException;

final class LinkCustomerEbillParticipantHandler
{
    public function __construct(private readonly ParticipantDirectoryService $directory) {}

    public function handle(LinkCustomerEbillParticipantCommand $command): Contact
    {
        $contact = Contact::query()->findOrFail($command->customerId);
        $record = $this->directory->lookup(ParticipantLookupCriteria::participantId($command->participantId), true);

        if ($record === null) {
            throw new EbillException('participant_not_found', 'The participant is not in the eBill directory.', 404);
        }

        return $this->directory->link($contact, $record);
    }
}
