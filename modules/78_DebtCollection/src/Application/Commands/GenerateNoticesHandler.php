<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

use Illuminate\Support\Carbon;
use Modules\DebtCollection\Models\Batch;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Services\BatchService;
use Modules\DebtCollection\Services\NoticeService;

final class GenerateNoticesHandler
{
    public function __construct(
        private NoticeService $notices,
        private BatchService $batches,
    ) {}

    /**
     * @return array{notices: list<Notice>, batch: Batch|null}
     */
    public function handle(GenerateNoticesCommand $command): array
    {
        $notices = [];

        foreach ($command->groups as $group) {
            $notices[] = $this->notices->generate($command->tenantId, $group, Carbon::parse($command->asOf), $command->channel, $command->locale, $command->postCharges, $command->actorId);
        }

        $batch = $command->dispatch && $notices !== []
            ? $this->batches->create($command->tenantId, array_map(static fn (Notice $notice): string => (string) $notice->id, $notices), $command->channel, $command->actorId)
            : null;

        return ['notices' => array_map(static fn (Notice $notice): Notice => $notice->refresh()->load(['lines', 'receipts']), $notices), 'batch' => $batch];
    }
}
