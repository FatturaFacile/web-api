<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Commands;

final readonly class PostQuoteCommentCommand
{
    public function __construct(
        public string $token,
        public ?string $session,
        public string $body,
        public ?string $lineId,
        public ?string $authorName,
        public ?string $ip = null,
    ) {}
}
