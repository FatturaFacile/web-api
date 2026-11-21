<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Modules\Quoting\Application\Commands\ReorderQuoteLinesCommand;
use Modules\Quoting\Application\Services\QuoteDraftWriter;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

final class ReorderQuoteLinesHandler
{
    public function __construct(private readonly QuoteDraftWriter $writer) {}

    public function handle(ReorderQuoteLinesCommand $command): CpqQuote
    {
        $quote = CpqQuote::query()->findOrFail($command->quoteId);

        return $this->writer->reorder($quote, $command->sectionOrder, $command->lineOrder)->load(['sections', 'lines']);
    }
}
