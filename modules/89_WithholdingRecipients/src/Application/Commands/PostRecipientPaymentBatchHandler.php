<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

use Modules\WithholdingRecipients\Events\RecipientPaymentPosted;
use Modules\WithholdingRecipients\Models\RecipientSettlement;
use Modules\WithholdingRecipients\Services\SettlementService;

final class PostRecipientPaymentBatchHandler
{
    public function __construct(private readonly SettlementService $settlements) {}

    /**
     * @return list<RecipientSettlement>
     */
    public function handle(PostRecipientPaymentBatchCommand $command): array
    {
        $posted = $this->settlements->postBatch($command->items, $command->userId);

        foreach ($posted as $settlement) {
            event(new RecipientPaymentPosted((string) $settlement->id, (string) $settlement->recipient_profile_id, (string) $settlement->tenant_id));
        }

        return $posted;
    }
}
