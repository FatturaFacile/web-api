<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Modules\Quoting\Application\Queries\CompareQuoteRevisionsQuery;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Domain\Services\QuoteRevisionDiffService;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Services\QuoteSnapshotBuilder;

final class CompareQuoteRevisionsHandler
{
    public function __construct(private readonly QuoteRevisionDiffService $diff, private readonly QuoteSnapshotBuilder $snapshots) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(CompareQuoteRevisionsQuery $query): array
    {
        $from = CpqQuote::query()->findOrFail($query->fromQuoteId);
        $to = CpqQuote::query()->findOrFail($query->toQuoteId);

        if (($from->root_quote_id ?? $from->id) !== ($to->root_quote_id ?? $to->id)) {
            throw new QuoteException('Only revisions of the same quote can be compared.');
        }

        return ['from' => $from->quote_number, 'to' => $to->quote_number] + $this->diff->diff(
            $from->frozen_snapshot ?? $this->snapshots->snapshot($from),
            $to->frozen_snapshot ?? $this->snapshots->snapshot($to),
        );
    }
}
