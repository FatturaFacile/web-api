<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Contracts;

use Illuminate\Database\Eloquent\Model;
use Modules\ElectronicSignature\DTOs\SignableDocument;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * Connects a kind of business document to the signature engine: renders it to PDF, tells its amount (which decides the
 * tier it needs) and reacts when the envelope is completed, declined or expired.
 */
interface SignableDocumentSource
{
    public function type(): SignableType;

    /**
     * The record of the active company, or null.
     */
    public function find(string $id): ?Model;

    public function document(Model $record, string $locale): SignableDocument;

    public function onCompleted(Model $record, SignatureEnvelope $envelope): void;

    public function onDeclined(Model $record, SignatureEnvelope $envelope): void;

    public function onExpired(Model $record, SignatureEnvelope $envelope): void;
}
