<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Queries;

use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Services\RecipientInsightService;

final class GetThresholdProgressHandler
{
    public function __construct(private readonly RecipientInsightService $insight) {}

    /**
     * @return list<array{key: string, current: string, limit: string, percent: float}>
     */
    public function handle(GetThresholdProgressQuery $query): array
    {
        return $this->insight->thresholdsFor(RecipientProfile::query()->findOrFail($query->recipientProfileId), $query->year);
    }
}
