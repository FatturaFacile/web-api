<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use App\Models\Contact;
use Modules\SwissEbill\Application\Dto\ParticipantRecord;
use Modules\SwissEbill\Application\Services\ParticipantDirectoryService;

final class SyncCustomerEbillStatusHandler
{
    public function __construct(private readonly ParticipantDirectoryService $directory) {}

    /**
     * @return array{contact: Contact, record: ParticipantRecord|null}
     */
    public function handle(SyncCustomerEbillStatusCommand $command): array
    {
        return $this->directory->syncCustomer(Contact::query()->findOrFail($command->customerId), $command->forceRefresh);
    }
}
