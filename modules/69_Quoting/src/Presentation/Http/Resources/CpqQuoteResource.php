<?php

declare(strict_types=1);

namespace Modules\Quoting\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

/**
 * Back-office view of a revision (carries unit costs and margin: never serve it on the public portal).
 *
 * @mixin CpqQuote
 */
final class CpqQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'root_quote_id' => $this->root_quote_id,
            'parent_quote_id' => $this->parent_quote_id,
            'quote_number' => $this->quote_number,
            'revision' => $this->revision,
            'status' => $this->status->value,
            'jurisdiction' => $this->jurisdiction,
            'locale' => $this->locale,
            'contact_id' => $this->contact_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->toDateString(),
            'payment_terms' => $this->payment_terms,
            'incoterms' => $this->incoterms,
            'cover_letter' => $this->cover_letter,
            'terms_and_conditions' => $this->terms_and_conditions,
            'header_discount_percent' => $this->header_discount_percent,
            'min_margin_percent' => $this->min_margin_percent,
            'requires_approval' => $this->requires_approval,
            'auto_convert' => $this->auto_convert,
            'auto_convert_target' => $this->auto_convert_target?->value,
            'signature_level' => $this->signature_level->value,
            'totals' => [
                'net' => $this->total_net, 'tax' => $this->total_tax, 'gross' => $this->total_gross,
                'cost' => $this->total_cost, 'margin_percent' => $this->margin_percent,
            ],
            'snapshot_hash' => $this->snapshot_hash,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'signed_document_hash' => $this->signed_document_hash,
            'audit_certificate_hash' => $this->audit_certificate_hash,
            'converted_sales_order_id' => $this->converted_sales_order_id,
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(static fn ($s): array => [
                'id' => $s->id, 'title' => $s->title, 'description' => $s->description, 'page_break_before' => $s->page_break_before,
                'discount_percent' => $s->discount_percent, 'sort_order' => $s->sort_order,
            ])->all()),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn ($l): array => [
                'id' => $l->id, 'section_id' => $l->section_id, 'line_type' => $l->line_type->value, 'selection_mode' => $l->selection_mode->value,
                'alternative_group' => $l->alternative_group, 'is_default_selected' => $l->is_default_selected, 'sku' => $l->sku, 'description' => $l->description,
                'uom' => $l->uom, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'unit_cost' => $l->unit_cost,
                'discount_percent' => $l->discount_percent, 'discount_fixed' => $l->discount_fixed, 'tier_prices' => $l->tier_prices,
                'tax_rate' => $l->tax_rate, 'sort_order' => $l->sort_order,
            ])->all()),
            'audit_events' => $this->whenLoaded('auditEvents', fn () => $this->auditEvents->map(static fn ($e): array => [
                'sequence' => $e->sequence, 'event' => $e->event, 'actor' => $e->actor, 'ip_address' => $e->ip_address,
                'occurred_at' => $e->occurred_at->toIso8601String(), 'event_hash' => $e->event_hash,
            ])->all()),
            'signature_envelopes' => $this->whenLoaded('envelopes', fn () => $this->envelopes->map(static fn ($e): array => [
                'id' => $e->id, 'level' => $e->level->value, 'provider' => $e->provider, 'status' => $e->status, 'signer_name' => $e->signer_name,
                'signed_at' => $e->signed_at?->toIso8601String(), 'document_hash' => $e->document_hash,
            ])->all()),
            'comments' => $this->whenLoaded('comments', fn () => $this->comments->map(static fn ($c): array => [
                'author_type' => $c->author_type, 'author_name' => $c->author_name, 'body' => $c->body, 'created_at' => $c->created_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
