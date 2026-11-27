<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Queries;

use Modules\SwissEbill\Application\Dto\ParticipantRecord;
use Modules\SwissEbill\Application\Services\ParticipantDirectoryService;

final class LookupEbillParticipantHandler
{
    public function __construct(private readonly ParticipantDirectoryService $directory) {}

    public function handle(LookupEbillParticipantQuery $query): ?ParticipantRecord
    {
        return $this->directory->lookup($query->criteria, $query->forceRefresh);
    }
}
