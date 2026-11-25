<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Services\NoticeService;

final class CancelNoticeHandler
{
    public function __construct(
        private NoticeService $notices,
    ) {}

    public function handle(CancelNoticeCommand $command): Notice
    {
        return $this->notices->cancel(Notice::query()->with('lines')->findOrFail($command->noticeId), $command->actorId)->load(['lines', 'receipts']);
    }
}
