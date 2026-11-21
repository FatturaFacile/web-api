<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Modules\Quoting\Application\Commands\CreateQuoteCommand;
use Modules\Quoting\Application\Services\QuoteDraftWriter;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

final class CreateQuoteHandler
{
    public function __construct(private readonly QuoteDraftWriter $writer) {}

    public function handle(CreateQuoteCommand $command): CpqQuote
    {
        return $this->writer->create($command->tenantId, $command->userId, $command->data)->load(['sections', 'lines']);
    }
}
