<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

final readonly class GenerateInlPreventiveNotificationCommand
{
    /**
     * @param  array<string, mixed>  $data  work_start_date, work_end_date, activity_description, work_place, estimated_compensation
     */
    public function __construct(
        public string $recipientProfileId,
        public array $data,
        public ?string $userId = null,
    ) {}
}
