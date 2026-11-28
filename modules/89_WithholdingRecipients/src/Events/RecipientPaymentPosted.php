<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Events;

final readonly class RecipientPaymentPosted
{
    public function __construct(
        public string $settlementId,
        public string $recipientProfileId,
        public string $tenantId,
    ) {}
}
