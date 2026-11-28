<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

final readonly class PostRecipientPaymentAndScheduleF24Command
{
    /**
     * @param  array<string, mixed>  $data  gross_amount, payment_date, service_date, document_number, payment_reference, notes, performance_days, acquisition_expenses
     */
    public function __construct(
        public string $recipientProfileId,
        public array $data,
        public ?string $userId = null,
    ) {}
}
