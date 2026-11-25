<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\DTOs;

/**
 * A business document ready to be signed: the file (a PDF, or an XML / other file for XAdES and CAdES), a title, and the amount that decides the signature tier it needs.
 */
final readonly class SignableDocument
{
    public function __construct(
        public string $pdf,
        public string $title,
        public ?string $amount = null,
        public ?string $currency = null,
        public string $format = 'pades',
        public ?string $fileName = null,
    ) {}
}
