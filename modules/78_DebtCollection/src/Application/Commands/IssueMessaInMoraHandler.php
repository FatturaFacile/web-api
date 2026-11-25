<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

use Illuminate\Support\Carbon;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Services\DispatchService;
use Modules\DebtCollection\Services\NoticeService;

final class IssueMessaInMoraHandler
{
    public function __construct(
        private NoticeService $notices,
        private DispatchService $dispatch,
    ) {}

    public function handle(IssueMessaInMoraCommand $command): Notice
    {
        $notice = $this->notices->messaInMora($command->tenantId, $command->customerId, $command->itemIds, Carbon::parse($command->asOf), 'pec', $command->locale, true, $command->actorId);

        if ($command->sendPec) {
            $notice = $this->dispatch->send($notice, 'pec', $command->actorId);
        }

        return $notice->load(['lines', 'receipts']);
    }
}
