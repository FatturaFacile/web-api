<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use Illuminate\Support\Collection;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\RfqSupplier;
use Modules\Procurement\Services\RfqService;
use Modules\SupplierPortal\Models\SupplierRiskProfile;

/**
 * Internal view of the answers of the invited suppliers to a request for quotation. The comparison of the
 * procurement module (prices per line, totals, coverage, lead time, recommended quote) is enriched with who answered,
 * who declined, who did not answer by the deadline, and the qualification and risk flag of every supplier, so the buyer
 * does not award to a supplier that is blocked or flagged.
 */
final readonly class RfqResponseService
{
    public function __construct(private RfqService $rfqs) {}

    /**
     * Requests that were issued, with how many suppliers answered.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function summaries(?string $status): Collection
    {
        return Rfq::query()
            ->withCount([
                'invitedSuppliers as invited_count',
                'invitedSuppliers as responded_count' => static fn ($query) => $query->where('status', 'responded'),
                'invitedSuppliers as declined_count' => static fn ($query) => $query->where('status', 'declined'),
            ])
            ->whereIn('status', $status !== null ? [$status] : ['issued', 'awarded', 'cancelled'])
            ->orderByDesc('response_deadline')
            ->limit(200)
            ->get()
            ->map(fn (Rfq $rfq): array => [
                'id' => (string) $rfq->id,
                'rfq_number' => (string) $rfq->rfq_number,
                'title' => (string) $rfq->title,
                'status' => (string) $rfq->status,
                'response_deadline' => $rfq->response_deadline->toDateString(),
                'is_open' => $this->isOpen($rfq),
                'currency' => (string) $rfq->currency,
                'invited_count' => (int) $rfq->getAttribute('invited_count'),
                'responded_count' => (int) $rfq->getAttribute('responded_count'),
                'declined_count' => (int) $rfq->getAttribute('declined_count'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function comparison(Rfq $rfq): array
    {
        $comparison = $this->rfqs->compare($rfq);
        $risk = SupplierRiskProfile::query()->whereIn('supplier_id', $rfq->invitedSuppliers()->pluck('supplier_id'))->get()->keyBy('supplier_id');

        $quotes = collect($comparison['quotes'])->map(static fn (array $quote): array => [
            ...$quote,
            'risk_level' => $risk->get($quote['supplier_id'])?->level ?? 'none',
        ]);

        $recommended = $quotes->first(static fn (array $quote): bool => $quote['covers_all_lines'] && ! in_array($quote['risk_level'], ['high', 'critical'], true));

        $invitations = $rfq->invitedSuppliers()->with('supplier')->get()->map(static fn (RfqSupplier $invitation): array => [
            'supplier_id' => (string) $invitation->supplier_id,
            'supplier_name' => $invitation->supplier?->company_name,
            'qualification_status' => $invitation->supplier?->qualification_status,
            'risk_level' => $risk->get($invitation->supplier_id)?->level ?? 'none',
            'status' => (string) $invitation->status,
            'invited_at' => $invitation->invited_at?->toIso8601String(),
            'responded_at' => $invitation->responded_at?->toIso8601String(),
        ]);

        return [
            'rfq' => [
                'id' => (string) $rfq->id,
                'rfq_number' => (string) $rfq->rfq_number,
                'title' => (string) $rfq->title,
                'status' => (string) $rfq->status,
                'response_deadline' => $rfq->response_deadline->toDateString(),
                'is_open' => $this->isOpen($rfq),
                'currency' => (string) $rfq->currency,
            ],
            'lines' => $comparison['lines'],
            'quotes' => $quotes->values()->all(),
            'invitations' => $invitations->values()->all(),
            'recommended_quote_id' => $recommended['quote_id'] ?? null,
            'awarded_quote_id' => $rfq->awarded_quote_id,
        ];
    }

    public function isOpen(Rfq $rfq): bool
    {
        return $rfq->status === 'issued' && $rfq->response_deadline->toDateString() >= now()->toDateString();
    }
}
