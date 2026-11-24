<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Modules\AnalyticsDss\Infrastructure\Projection\MartProjector;

/**
 * Queued worker that projects one operational document into the analytical data mart. Idempotent: a retry rebuilds the same
 * facts, it never adds to them.
 */
final class UpdateAnalyticalMartJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string SourceJournalEntry = 'journal_entry';

    public const string SourceInvoice = 'invoice';

    public const string SourceTimesheet = 'timesheet';

    public const string SourceStockMovement = 'stock_movement';

    public int $tries = 3;

    public function __construct(public readonly string $tenantId, public readonly string $source, public readonly string $sourceId)
    {
        $this->onQueue('analytics');
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(MartProjector $projector): void
    {
        match ($this->source) {
            self::SourceJournalEntry => $projector->projectJournalEntry($this->tenantId, $this->sourceId),
            self::SourceInvoice => $projector->projectInvoice($this->tenantId, $this->sourceId),
            self::SourceTimesheet => $projector->projectTimesheet($this->tenantId, $this->sourceId),
            self::SourceStockMovement => $this->projectStock($projector),
            default => throw new InvalidArgumentException("Unknown analytical mart source [{$this->source}]."),
        };
    }

    private function projectStock(MartProjector $projector): void
    {
        $projector->projectStockMovement($this->tenantId, $this->sourceId);
        $projector->classifyProducts($this->tenantId);
    }
}
