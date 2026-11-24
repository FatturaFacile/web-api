<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\AiCognitive\Domain\Contracts\AiProviderGatewayInterface;
use Modules\AiCognitive\Infrastructure\Models\AiEmbedding;

/**
 * Tenant-isolated retrieval index over the ERP (customers, invoices, quotes, tickets) and the Swiss and Italian fiscal
 * rules. Vectors live in `ai_embeddings` as JSON; the ranking is a cosine similarity computed in PHP over the candidates of
 * the tenant, which behaves the same on SQLite and PostgreSQL (a pgvector index can replace it without touching the callers).
 * Every row and every query is scoped to the tenant, and text sent to a hosted embedding model is masked by the gateway.
 */
final readonly class VectorIndexService
{
    public function __construct(
        private AiProviderGatewayInterface $gateway,
        private AiTenantRunner $runner,
    ) {}

    /**
     * Rebuilds the index of the tenant from its ERP data; unchanged chunks keep their vector (same content hash).
     *
     * @return array{indexed: int, reused: int, removed: int}
     */
    public function rebuild(string $tenantId): array
    {
        return $this->runner->run($tenantId, function () use ($tenantId): array {
            $indexed = 0;
            $reused = 0;
            $seen = [];

            foreach ($this->documents($tenantId) as $document) {
                foreach ($this->chunks($document['text']) as $position => $chunk) {
                    $hash = hash('sha256', $chunk);
                    $seen[$document['type'].'|'.$document['id'].'|'.$position] = true;
                    $existing = AiEmbedding::query()->where('tenant_id', $tenantId)->where('source_type', $document['type'])->where('source_id', $document['id'])->where('chunk_index', $position)->first();

                    if ($existing !== null && $existing->content_hash === $hash) {
                        $reused++;

                        continue;
                    }

                    $vector = $this->gateway->embed($tenantId, $chunk);

                    AiEmbedding::query()->updateOrCreate(
                        ['tenant_id' => $tenantId, 'source_type' => $document['type'], 'source_id' => $document['id'], 'chunk_index' => $position],
                        ['content' => $chunk, 'content_hash' => $hash, 'embedding_json' => $vector, 'dimension' => count($vector), 'model' => 'tenant-default', 'metadata_json' => $document['metadata']],
                    );
                    $indexed++;
                }
            }

            $removed = 0;

            foreach (AiEmbedding::query()->where('tenant_id', $tenantId)->get(['id', 'source_type', 'source_id', 'chunk_index']) as $row) {
                if (! isset($seen[$row->source_type.'|'.$row->source_id.'|'.$row->chunk_index])) {
                    $row->forceDelete();
                    $removed++;
                }
            }

            return ['indexed' => $indexed, 'reused' => $reused, 'removed' => $removed];
        });
    }

    /**
     * The best passages for a question, most similar first, each with its citation.
     *
     * @return list<array{score: float, source_type: string, source_id: string, content: string, metadata: array<string, mixed>}>
     */
    public function search(string $tenantId, string $query, ?int $topK = null): array
    {
        $topK ??= (int) config('ai_cognitive.rag.top_k', 5);
        $vector = $this->gateway->embed($tenantId, $query);
        $scored = [];

        $candidates = AiEmbedding::query()->where('tenant_id', $tenantId)->orderByDesc('updated_at')->limit((int) config('ai_cognitive.rag.candidates', 2000))->get();

        foreach ($candidates as $candidate) {
            // A vector of another dimension comes from another embedding model: it cannot be compared.
            if ($candidate->dimension !== count($vector)) {
                continue;
            }

            $scored[] = ['score' => round($this->cosine($vector, $candidate->embedding_json), 4), 'source_type' => $candidate->source_type, 'source_id' => $candidate->source_id, 'content' => $candidate->content, 'metadata' => $candidate->metadata_json ?? []];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $topK);
    }

    /**
     * @return iterable<array{type: string, id: string, text: string, metadata: array<string, mixed>}>
     */
    private function documents(string $tenantId): iterable
    {
        foreach (DB::table('contacts')->where('tenant_id', $tenantId)->whereNull('deleted_at')->limit(5000)->get(['id', 'company_name', 'first_name', 'last_name', 'type', 'country']) as $contact) {
            yield ['type' => 'contact', 'id' => (string) $contact->id, 'text' => trim("Contact {$contact->type}: ".($contact->company_name ?? '').' '.($contact->first_name ?? '').' '.($contact->last_name ?? '').", country {$contact->country}"), 'metadata' => ['route' => '/contacts/'.$contact->id]];
        }

        foreach (DB::table('invoices')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderByDesc('date')->limit(5000)->get(['id', 'invoice_number', 'date', 'due_date', 'currency', 'total_gross', 'payment_status', 'notes']) as $invoice) {
            yield ['type' => 'invoice', 'id' => (string) $invoice->id, 'text' => "Invoice {$invoice->invoice_number} of {$invoice->date}, due {$invoice->due_date}, total {$invoice->total_gross} {$invoice->currency}, status {$invoice->payment_status}. ".($invoice->notes ?? ''), 'metadata' => ['route' => '/invoices/'.$invoice->id]];
        }

        foreach (DB::table('quotes')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderByDesc('date')->limit(2000)->get(['id', 'quote_number', 'date', 'currency', 'total_gross', 'status']) as $quote) {
            yield ['type' => 'quote', 'id' => (string) $quote->id, 'text' => "Quote {$quote->quote_number} of {$quote->date}, total {$quote->total_gross} {$quote->currency}, status {$quote->status}.", 'metadata' => ['route' => '/quotes/'.$quote->id]];
        }

        foreach (DB::table('esm_tickets')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderByDesc('created_at')->limit(2000)->get(['id', 'ticket_number', 'subject', 'description', 'status']) as $ticket) {
            yield ['type' => 'ticket', 'id' => (string) $ticket->id, 'text' => "Ticket {$ticket->ticket_number} ({$ticket->status}): {$ticket->subject}. {$ticket->description}", 'metadata' => ['route' => '/tickets/'.$ticket->id]];
        }

        foreach ($this->fiscalRules() as $key => $text) {
            yield ['type' => 'fiscal_rule', 'id' => $key, 'text' => $text, 'metadata' => []];
        }
    }

    /**
     * @return array<string, string>
     */
    private function fiscalRules(): array
    {
        return [
            'ch_vat_rates' => 'Switzerland MWST: standard rate 8.1%, reduced rate 2.6% for food and books, special rate 3.8% for accommodation. Exports are exempt.',
            'ch_qr_bill' => 'Swiss QR-bill: the payment slip needs the creditor IBAN or QR-IBAN, the creditor and debtor name and address, the amount and currency (CHF or EUR) and a QR reference or SCOR reference.',
            'it_vat_rates' => 'Italy IVA: ordinary rate 22%, reduced rates 10%, 5% and 4%. Reverse charge (inversione contabile) applies to defined domestic supplies; the invoice shows the nature code N6.x.',
            'it_sdi' => 'Italy SDI: the electronic invoice (FatturaPA XML) is sent through the Sistema di Interscambio; the customer is reached by SDI code or PEC; the document needs customer VAT number or Codice Fiscale.',
            'ch_retention' => 'Switzerland GeBüV and Art. 958f CO: business records are kept for ten years in a form that cannot be altered.',
            'it_retention' => 'Italy: accounting records and invoices are kept for ten years (art. 2220 Codice Civile) and may be preserved digitally under the conservazione sostitutiva rules.',
        ];
    }

    /**
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        $size = max(200, (int) config('ai_cognitive.rag.chunk_chars', 900));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        return mb_strlen($text) <= $size ? [$text] : array_values(array_filter(array_map('trim', mb_str_split($text, $size))));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $other = (float) ($b[$index] ?? 0.0);
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        return $normA > 0.0 && $normB > 0.0 ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }
}
