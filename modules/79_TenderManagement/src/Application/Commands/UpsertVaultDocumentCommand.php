<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Commands;

use Illuminate\Http\UploadedFile;

/**
 * Creates, updates or renews a qualification vault document. A renewal names the document it replaces.
 */
final readonly class UpsertVaultDocumentCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public ?UploadedFile $file,
        public ?string $userId,
        public ?string $documentId = null,
        public bool $renew = false,
    ) {}
}
