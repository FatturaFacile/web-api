<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

final readonly class CalculateRecipientCompensationWithholdingCommand
{
    /**
     * @param  array<string, mixed>  $data  gross_amount, payment_date, service_date and, without a recipient, the payee facts
     */
    public function __construct(
        public array $data,
        public ?string $recipientProfileId = null,
    ) {}
}
