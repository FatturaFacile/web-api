<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;

final readonly class PostJournalEntryAction
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, ?string $actorUserId = null): JournalEntry
    {
        return $this->postingService->createAndPost($data, $actorUserId);
    }
}
