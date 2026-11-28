<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Queries;

final readonly class GetThresholdProgressQuery
{
    public function __construct(
        public string $recipientProfileId,
        public int $year,
    ) {}
}
