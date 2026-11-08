<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versions and validity of the quotes.
 *
 * A quote still open (draft, pending approval, approved or sent) can be revised: the revision is a new quote numbered on
 * the quote series, with the next revision number, a link to the quote it replaces and the lines it is given (or those
 * of the replaced quote); the replaced quote becomes superseded and can no longer be accepted or converted.
 *
 * A quote is valid until its `valid_until` date, on the calendar of the company. Past that date an open quote can no
 * longer be accepted or converted, and the expiry run moves it to expired.
 */
final readonly class QuoteLifecycleService
{
    /**
     * Statuses a quote can be revised or expired from.
     *
     * @var list<string>
     */
    public const array OpenStatuses = ['draft', 'pending_approval', 'approved', 'sent'];

    public function __construct(
        private DocumentNumberingService $numbering,
        private DocumentTotalsCalculator $totals,
        private DocumentEventRecorder $events,
        private TenantClock $clock,
    ) {}

    /**
     * Refuses to accept or convert an open quote (of the app or of the invoicing module) past its validity date. An accepted quote stays convertible: the
     * customer accepted it while it was valid.
     *
     * @throws ValidationException
     */
    public function assertWithinValidity(Model $quote): void
    {
        $validUntil = $quote->getAttribute('valid_until');

        if ($quote->getAttribute('status') === QuoteStatus::Accepted->value || ! $validUntil instanceof CarbonInterface) {
            return;
        }

        if ($validUntil->toDateString() < $this->today((string) $quote->getAttribute('tenant_id'))->toDateString()) {
            throw ValidationException::withMessages(['quote' => __('sales.quote_past_validity')]);
        }
    }

    /**
     * Creates the next revision of an open quote and supersedes it.
     *
     * @param  array{date?: string, valid_until?: ?string, notes?: ?string, custom_text?: ?string, items?: array<int, array<string, mixed>>}  $changes
     *
     * @throws ValidationException when the quote is not open
     */
    public function revise(Quote $quote, array $changes): Quote
    {
        return DB::transaction(function () use ($quote, $changes): Quote {
            $locked = Quote::query()->with('items')->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::OpenStatuses, true)) {
                throw ValidationException::withMessages(['status' => __('sales.quote_not_revisable')]);
            }

            QuoteStatus::assertTransition($locked->status, QuoteStatus::Superseded);
            $tenantId = (string) $locked->tenant_id;
            $date = CarbonImmutable::parse($changes['date'] ?? $this->today($tenantId)->toDateString());
            $sourceItems = $changes['items'] ?? $locked->items->map(static fn (QuoteItem $item): array => [
                'product_id' => $item->product_id,
                'description' => (string) $item->description,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'tax_rate' => (string) $item->tax_rate,
                'discount_percentage' => (string) ($item->discount_percentage ?? '0'),
            ])->values()->all();
            $computed = $this->totals->calculateDecimal($sourceItems);
            $number = $this->numbering->next('quote', $date->year, $tenantId);

            $revision = new Quote;
            $revision->forceFill([
                'tenant_id' => $tenantId,
                'contact_id' => $locked->contact_id,
                'quote_number' => $number['number'],
                'date' => $date->toDateString(),
                'valid_until' => array_key_exists('valid_until', $changes) ? $changes['valid_until'] : $locked->getRawOriginal('valid_until'),
                'currency' => $locked->currency,
                'subtotal' => $computed['subtotal'],
                'discount_amount' => $computed['discount_total'],
                'total_net' => $computed['total_net'],
                'total_tax' => $computed['total_tax'],
                'total_gross' => $computed['total_gross'],
                'status' => QuoteStatus::Draft->value,
                'notes' => array_key_exists('notes', $changes) ? $changes['notes'] : $locked->notes,
                'custom_text' => array_key_exists('custom_text', $changes) ? $changes['custom_text'] : $locked->custom_text,
                'price_list_id' => $locked->getAttribute('price_list_id'),
                'discount_policy_id' => $locked->getAttribute('discount_policy_id'),
                'payment_terms' => $locked->getAttribute('payment_terms'),
                'shipping_terms' => $locked->getAttribute('shipping_terms'),
                'revision_number' => ((int) ($locked->getAttribute('revision_number') ?? 1)) + 1,
                'revision_of_quote_id' => $locked->id,
            ])->save();

            foreach ($computed['items'] as $index => $line) {
                $revision->items()->make([
                    'product_id' => $sourceItems[$index]['product_id'] ?? null,
                    'description' => (string) $sourceItems[$index]['description'],
                ] + $line)->forceFill(['tenant_id' => $tenantId])->save();
            }

            $locked->forceFill(['status' => QuoteStatus::Superseded->value, 'superseded_at' => now()])->save();
            $this->events->record($locked, 'superseded', ['revision_quote_id' => $revision->id]);
            $this->events->record($revision, 'created', ['revision_of_quote_id' => $locked->id]);

            return $revision->load(['items', 'contact']);
        });
    }

    /**
     * Moves the open quotes of the active company past their validity date to expired; returns how many.
     */
    public function expireDue(string $tenantId): int
    {
        $today = $this->today($tenantId)->toDateString();
        $expired = 0;

        $due = Quote::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [QuoteStatus::Draft->value, QuoteStatus::Approved->value, QuoteStatus::Sent->value])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', $today)
            ->pluck('id');

        foreach ($due as $quoteId) {
            $expired += DB::transaction(function () use ($quoteId): int {
                $locked = Quote::query()->whereKey($quoteId)->lockForUpdate()->first();

                if ($locked === null || ! QuoteStatus::allows($locked->status, QuoteStatus::Expired) || $locked->status === QuoteStatus::Expired->value) {
                    return 0;
                }

                $locked->update(['status' => QuoteStatus::Expired->value]);
                $this->events->record($locked, 'expired');

                return 1;
            });
        }

        return $expired;
    }

    private function today(string $tenantId): CarbonImmutable
    {
        return $this->clock->today(Tenant::query()->find($tenantId));
    }
}
