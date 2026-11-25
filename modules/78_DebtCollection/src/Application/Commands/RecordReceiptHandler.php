<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

use Illuminate\Support\Carbon;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\PecReceipt;
use Modules\DebtCollection\Services\ReceiptService;

final class RecordReceiptHandler
{
    public function __construct(
        private ReceiptService $receipts,
    ) {}

    public function handle(RecordReceiptCommand $command): PecReceipt
    {
        return $this->receipts->record(
            Notice::query()->findOrFail($command->noticeId),
            $command->kind,
            $command->messageId,
            $command->receivedAt === null ? null : Carbon::parse($command->receivedAt),
            $command->notes,
            $command->actorId,
        );
    }
}
