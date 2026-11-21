<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Modules\Quoting\Application\Commands\UpdateDraftQuoteCommand;
use Modules\Quoting\Application\Services\QuoteDraftWriter;
use Modules\Quoting\Domain\Exceptions\ImmutableQuoteRevisionException;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

final class UpdateDraftQuoteHandler
{
    public function __construct(private readonly QuoteDraftWriter $writer) {}

    /** @throws ImmutableQuoteRevisionException */
    public function handle(UpdateDraftQuoteCommand $command): CpqQuote
    {
        $quote = CpqQuote::query()->findOrFail($command->quoteId);

        return $this->writer->update($quote, $command->userId, $command->data)->load(['sections', 'lines']);
    }
}
