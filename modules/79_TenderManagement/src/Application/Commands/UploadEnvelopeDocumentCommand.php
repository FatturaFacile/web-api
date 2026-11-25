<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Commands;

use Illuminate\Http\UploadedFile;

/**
 * Stores a document in one of the three envelopes and validates its .p7m / PAdES signature.
 */
final readonly class UploadEnvelopeDocumentCommand
{
    public function __construct(
        public string $participationId,
        public string $envelope,
        public string $docKey,
        public UploadedFile $file,
    ) {}
}
