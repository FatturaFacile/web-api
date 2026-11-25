<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Services\DispatchService;

final class SendNoticeHandler
{
    public function __construct(
        private DispatchService $dispatch,
    ) {}

    public function handle(SendNoticeCommand $command): Notice
    {
        return $this->dispatch->send(Notice::query()->findOrFail($command->noticeId), $command->channel, $command->actorId)->load(['lines', 'receipts']);
    }
}
