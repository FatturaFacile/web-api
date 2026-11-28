<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

final readonly class GenerateAnnualCuWithholdingReturnCommand
{
    public function __construct(
        public int $taxYear,
        public ?string $recipientProfileId = null,
        public ?string $userId = null,
    ) {}
}
