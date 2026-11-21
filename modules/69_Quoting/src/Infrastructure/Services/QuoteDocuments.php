<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Quoting\Domain\Contracts\QuoteDocumentRenderer;
use Modules\Quoting\Domain\ValueObjects\DocumentHash;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Models\CpqSignatureEnvelope;

/**
 * The two archival documents of an accepted quote: the proposal with the exact selections of the customer, and the
 * certificate of completion with the hash-chained event log. Stored on the quoting disk; files are written by the
 * handlers outside any database transaction.
 */
final class QuoteDocuments
{
    public function __construct(
        private readonly QuoteDocumentRenderer $renderer,
        private readonly QuoteSnapshotBuilder $snapshots,
        private readonly QuoteAuditTrail $audit,
    ) {}

    /**
     * @param  array<string, bool>  $selections
     * @return array{path: string, hash: DocumentHash, pdfa_compliant: bool}
     */
    public function storeSignedProposal(CpqQuote $quote, array $selections): array
    {
        $view = $this->snapshots->publicView($quote, $selections);
        $rendered = $this->renderer->render($this->proposalHtml($view), [
            'title' => $quote->quote_number, 'author' => (string) ($view['customer_name'] ?? ''), 'subject' => 'Commercial proposal '.$quote->quote_number, 'keywords' => 'proposal, quote',
        ]);

        $path = $this->directory($quote).'/proposal.pdf';
        Storage::disk($this->disk())->put($path, $rendered['bytes']);

        return ['path' => $path, 'hash' => DocumentHash::ofBytes($rendered['bytes']), 'pdfa_compliant' => $rendered['pdfa_compliant']];
    }

    /**
     * @return array{path: string, hash: DocumentHash}
     */
    public function storeCertificate(CpqQuote $quote, CpqSignatureEnvelope $envelope): array
    {
        $quote->refresh();
        $rows = '';
        foreach ($quote->auditEvents as $event) {
            $rows .= '<tr><td>'.$event->sequence.'</td><td>'.e($event->occurred_at->utc()->format('Y-m-d H:i:s')).' UTC</td><td>'.e($event->event).'</td><td>'.e((string) $event->actor).'</td><td>'
                .e((string) $event->ip_address).'</td><td style="font-size:7px">'.e(substr($event->event_hash, 0, 16)).'</td></tr>';
        }

        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:10px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #999;padding:3px}</style></head><body>'
            .'<h2>Certificate of completion</h2>'
            .'<p>Document: '.e($quote->quote_number).' (revision '.$quote->revision.')<br>SHA-256 of the signed proposal: '.e((string) $quote->signed_document_hash).'<br>'
            .'Signature level: '.e($envelope->level->value).' - provider: '.e($envelope->provider).'<br>Signer: '.e($envelope->signer_name).' '.e((string) $envelope->signer_role).'<br>'
            .'Provider reference / verification: '.e((string) $envelope->provider_reference).' '.e((string) json_encode($envelope->verification_metadata)).'<br>'
            .'Event chain intact: '.($this->audit->verify($quote) ? 'yes' : 'NO').'</p>'
            .'<table><tr><th>#</th><th>Time</th><th>Event</th><th>Actor</th><th>IP</th><th>Hash</th></tr>'.$rows.'</table></body></html>';

        $rendered = $this->renderer->render($html, ['title' => 'Certificate '.$quote->quote_number, 'author' => 'FatturaFacile', 'subject' => 'Audit trail', 'keywords' => 'audit, eIDAS, ZertES']);
        $path = $this->directory($quote).'/audit-certificate.pdf';
        Storage::disk($this->disk())->put($path, $rendered['bytes']);

        return ['path' => $path, 'hash' => DocumentHash::ofBytes($rendered['bytes'])];
    }

    public function contents(string $path): ?string
    {
        return Storage::disk($this->disk())->exists($path) ? Storage::disk($this->disk())->get($path) : null;
    }

    /**
     * @param  array<string, mixed>  $view
     */
    private function proposalHtml(array $view): string
    {
        $currency = e((string) $view['currency']);
        $body = '<h1>'.e((string) $view['quote_number']).'</h1><p>'.e((string) $view['customer_name']).'</p>'.(string) ($view['cover_letter'] === null ? '' : '<div>'.strip_tags((string) $view['cover_letter'], '<p><br><b><i><ul><li>').'</div>');

        foreach ((array) $view['sections'] as $section) {
            $body .= '<h3>'.e((string) $section['title']).'</h3><table><tr><th>Description</th><th>Qty</th><th>Unit</th><th>Net</th></tr>';
            foreach ((array) $view['lines'] as $line) {
                if ($line['section_id'] !== $section['id'] || ! $line['selected']) {
                    continue;
                }
                $body .= '<tr><td>'.e((string) $line['description']).'</td><td>'.e((string) $line['quantity']).'</td><td>'.e((string) $line['unit_price']).'</td><td>'.e((string) $line['net']).'</td></tr>';
            }
            $body .= '</table><p>Subtotal: '.e((string) $section['subtotal']).' '.$currency.'</p>';
        }

        $totals = (array) $view['totals'];
        $body .= '<p>Net '.e((string) $totals['net']).' - VAT '.e((string) $totals['tax']).' - <b>Total '.e((string) $totals['payable']).' '.$currency.'</b></p>';

        return '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:11px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #bbb;padding:3px}</style></head><body>'.$body.'</body></html>';
    }

    private function directory(CpqQuote $quote): string
    {
        return 'quotes/'.$quote->tenant_id.'/'.$quote->id;
    }

    private function disk(): string
    {
        return (string) config('quoting.storage_disk', 'local');
    }
}
