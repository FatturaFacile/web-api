<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\Quoting\Application\Commands\CreateNewQuoteRevisionCommand;
use Modules\Quoting\Application\Services\QuoteDraftWriter;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\Events\QuoteRevisionCreated;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Domain\ValueObjects\QuoteNumber;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;
use Modules\Quoting\Infrastructure\Services\QuoteSnapshotBuilder;

/**
 * Clones the latest frozen revision into a new draft numbered .XX+1 and keeps the chain (parent, root, origin ids of the
 * sections and lines, which the diff matches on).
 */
final class CreateNewQuoteRevisionHandler
{
    public function __construct(
        private readonly QuoteDraftWriter $writer,
        private readonly QuoteSnapshotBuilder $snapshots,
        private readonly QuoteAuditTrail $audit,
    ) {}

    public function handle(CreateNewQuoteRevisionCommand $command): CpqQuote
    {
        $draft = DB::transaction(function () use ($command): CpqQuote {
            $source = CpqQuote::query()->whereKey($command->quoteId)->lockForUpdate()->firstOrFail();

            if (! $source->status->allowsNewRevision()) {
                throw new QuoteException("Quote {$source->quote_number} is {$source->status->value}: a new revision cannot be created from it.");
            }

            $rootId = $source->root_quote_id ?? $source->id;
            $latest = CpqQuote::query()->where('root_quote_id', $rootId)->orderByDesc('revision')->firstOrFail();

            if ($latest->id !== $source->id) {
                throw new QuoteException("Quote {$source->quote_number} is not the latest revision ({$latest->quote_number}).");
            }

            $number = QuoteNumber::parse($source->quote_number)->nextRevision();
            $structure = $this->snapshots->structure($source);

            $draft = new CpqQuote([
                'tenant_id' => $source->tenant_id,
                'root_quote_id' => $rootId,
                'parent_quote_id' => $source->id,
                'quote_number' => (string) $number,
                'number_prefix' => $source->number_prefix,
                'number_year' => $source->number_year,
                'number_sequence' => $source->number_sequence,
                'revision' => $number->revision,
                'status' => QuoteStatus::Draft->value,
                'created_by' => $command->userId,
            ]);
            $draft->fill(collect($source->getAttributes())->only([
                'jurisdiction', 'locale', 'contact_id', 'sales_rep_id', 'customer_name', 'customer_email', 'customer_phone', 'currency', 'exchange_rate',
                'payment_terms', 'incoterms', 'cover_letter', 'terms_and_conditions', 'header_discount_percent', 'min_margin_percent', 'auto_convert',
                'auto_convert_target', 'signature_level',
            ])->all());
            $draft->billing_address = $source->billing_address;
            $draft->shipping_address = $source->shipping_address;
            $draft->save();
            $draft->refresh();

            $sections = array_map(static fn (array $s): array => $s + ['key' => $s['id']], $structure['sections']);
            $lines = array_map(static fn (array $l): array => $l + ['section_key' => $l['section_id']], $structure['lines']);
            $this->writer->replaceStructure($draft, $sections, $lines);
            $this->writer->recalculate($draft);
            $this->audit->record($draft, 'revision_created', $command->userId, null, ['from' => $source->quote_number]);

            return $draft;
        });

        event(new QuoteRevisionCreated($draft->id, (string) $draft->parent_quote_id));

        return $draft->load(['sections', 'lines']);
    }
}
