<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Modules\Quoting\Application\Queries\GetPublicQuoteProposalQuery;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\Events\QuoteViewedByCustomer;
use Modules\Quoting\Infrastructure\Services\PublicQuoteGate;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;
use Modules\Quoting\Infrastructure\Services\QuoteSnapshotBuilder;

/**
 * The customer-facing proposal: a sanitized view that never carries unit costs, margins or internal totals.
 */
final class GetPublicQuoteProposalHandler
{
    public function __construct(
        private readonly PublicQuoteGate $gate,
        private readonly QuoteSnapshotBuilder $snapshots,
        private readonly QuoteAuditTrail $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetPublicQuoteProposalQuery $query): array
    {
        $quote = $this->gate->resolve($query->token, $query->session, allowClosed: true);

        if ($quote->status === QuoteStatus::Sent) {
            $quote->forceFill(['status' => QuoteStatus::Viewed->value, 'viewed_at' => now()])->save();
            $this->audit->record($quote, 'viewed', 'customer', $query->ip);
            event(new QuoteViewedByCustomer($quote->id, $query->ip));
        }

        return $this->snapshots->publicView($quote, (array) $quote->customer_selections) + [
            'has_signed_document' => $quote->signed_document_path !== null,
            'comments' => $quote->comments()->get(['author_type', 'author_name', 'body', 'created_at'])->all(),
        ];
    }
}
