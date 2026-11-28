<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

use Modules\WithholdingRecipients\Events\RecipientPaymentPosted;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Models\RecipientSettlement;
use Modules\WithholdingRecipients\Services\SettlementService;

final class PostRecipientPaymentAndScheduleF24Handler
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function handle(PostRecipientPaymentAndScheduleF24Command $command): RecipientSettlement
    {
        $profile = RecipientProfile::query()->findOrFail($command->recipientProfileId);
        $settlement = $this->settlements->post($profile, $command->data, $command->userId);
        event(new RecipientPaymentPosted((string) $settlement->id, (string) $settlement->recipient_profile_id, (string) $settlement->tenant_id));

        return $settlement;
    }
}
