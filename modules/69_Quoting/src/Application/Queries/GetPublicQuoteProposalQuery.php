<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Queries;

final readonly class GetPublicQuoteProposalQuery
{
    public function __construct(
        public string $token,
        public ?string $session,
        public ?string $ip = null,
    ) {}
}
