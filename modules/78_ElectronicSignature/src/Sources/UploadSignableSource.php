<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Sources;

use Illuminate\Database\Eloquent\Model;
use Modules\ElectronicSignature\Contracts\SignableDocumentSource;
use Modules\ElectronicSignature\DTOs\SignableDocument;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * A standalone file uploaded with the request: there is no business record, so nothing reacts to the outcome.
 */
final class UploadSignableSource implements SignableDocumentSource
{
    public function type(): SignableType
    {
        return SignableType::Upload;
    }

    public function find(string $id): ?Model
    {
        return null;
    }

    public function document(Model $record, string $locale): SignableDocument
    {
        throw new SignatureException('document_not_found', 404);
    }

    public function onCompleted(Model $record, SignatureEnvelope $envelope): void {}

    public function onDeclined(Model $record, SignatureEnvelope $envelope): void {}

    public function onExpired(Model $record, SignatureEnvelope $envelope): void {}
}
