<?php

declare(strict_types=1);

namespace Modules\SDI\DTOs;

final readonly class SignedPayload
{
    public function __construct(
        public string $content,
        public string $fileName,
        public string $mimeType,
    ) {}
}
