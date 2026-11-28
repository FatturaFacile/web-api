<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

use Modules\WithholdingRecipients\Models\OccasionalInlNotification;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Services\InlNotificationService;

final class GenerateInlPreventiveNotificationHandler
{
    public function __construct(private readonly InlNotificationService $notifications) {}

    public function handle(GenerateInlPreventiveNotificationCommand $command): OccasionalInlNotification
    {
        return $this->notifications->generate(RecipientProfile::query()->findOrFail($command->recipientProfileId), $command->data, $command->userId);
    }
}
