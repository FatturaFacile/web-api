<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

final readonly class GenerateDossierCommand
{
    public function __construct(
        public string $tenantId,
        public string $customerId,
        /** @var list<string> */
        public array $itemIds,
        public ?string $officeId,
        public ?string $locale,
        public string $asOf,
        public ?string $actorId,
    ) {}
}
