<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Sources;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\Translation;
use App\Services\Pdf\DocumentPdfService;
use Illuminate\Database\Eloquent\Model;
use Modules\ElectronicSignature\Contracts\SignableDocumentSource;
use Modules\ElectronicSignature\DTOs\SignableDocument;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * Quotes (preventivi): the PDF is the one the sales module issues; a completed signature accepts the quote, a declined
 * one rejects it, an expired one leaves it as it is.
 */
final class QuoteSignableSource implements SignableDocumentSource
{
    public function __construct(private readonly DocumentPdfService $pdf) {}

    public function type(): SignableType
    {
        return SignableType::Quote;
    }

    public function find(string $id): ?Model
    {
        return Quote::query()->find($id);
    }

    public function document(Model $record, string $locale): SignableDocument
    {
        if (! $record instanceof Quote) {
            throw new SignatureException('document_not_found', 404);
        }

        $rendered = $this->pdf->render($record, ['locale' => Translation::baseLocale($locale)]);

        return new SignableDocument($rendered->content, (string) $record->quote_number, (string) $record->total_gross, (string) $record->currency, fileName: $rendered->filename);
    }

    public function onCompleted(Model $record, SignatureEnvelope $envelope): void
    {
        if (! $record instanceof Quote) {
            return;
        }

        $current = QuoteStatus::tryFrom((string) $record->status);

        if ($current === null || ! in_array(QuoteStatus::Accepted, $current->transitions(), true)) {
            return;
        }

        $signers = $envelope->recipients()->where('status', 'signed')->orderBy('signed_at')->get();

        $record->forceFill([
            'status' => QuoteStatus::Accepted->value,
            'signed_at' => $envelope->completed_at,
            'signed_by' => $signers->pluck('full_name')->implode(', '),
        ])->save();
    }

    public function onDeclined(Model $record, SignatureEnvelope $envelope): void
    {
        if (! $record instanceof Quote) {
            return;
        }

        $current = QuoteStatus::tryFrom((string) $record->status);

        if ($current !== null && in_array(QuoteStatus::Rejected, $current->transitions(), true)) {
            $record->forceFill(['status' => QuoteStatus::Rejected->value])->save();
        }
    }

    public function onExpired(Model $record, SignatureEnvelope $envelope): void {}
}
