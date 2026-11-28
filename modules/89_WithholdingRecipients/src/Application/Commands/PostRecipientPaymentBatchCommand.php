<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

final readonly class PostRecipientPaymentBatchCommand
{
    /**
     * @param  list<array<string, mixed>>  $items  each item carries recipient_profile_id and the fields of a single posting
     */
    public function __construct(
        public array $items,
        public ?string $userId = null,
    ) {}
}
