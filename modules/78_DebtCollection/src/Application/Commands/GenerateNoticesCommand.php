<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

final readonly class GenerateNoticesCommand
{
    public function __construct(
        public string $tenantId,
        /** @var list<array{customer_id: string, currency: string, level: int, receivable_item_ids: list<string>}> */
        public array $groups,
        public string $asOf,
        public string $channel,
        public ?string $locale,
        public bool $postCharges,
        public bool $dispatch,
        public ?string $actorId,
    ) {}
}
