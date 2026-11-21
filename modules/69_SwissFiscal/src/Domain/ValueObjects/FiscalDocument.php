<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\ValueObjects;

/** A generated fiscal document: its format, MIME type, binary/text content and strategy-specific metadata. */
final readonly class FiscalDocument
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $format,
        public string $mimeType,
        public string $content,
        public string $filename,
        public array $metadata = [],
    ) {}
}
