<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

final readonly class IssueMessaInMoraCommand
{
    public function __construct(
        public string $tenantId,
        public string $customerId,
        /** @var list<string> */
        public array $itemIds,
        public bool $sendPec,
        public ?string $locale,
        public string $asOf,
        public ?string $actorId,
    ) {}
}
