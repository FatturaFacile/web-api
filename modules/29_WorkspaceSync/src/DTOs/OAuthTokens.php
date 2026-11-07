<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\DTOs;

use Carbon\CarbonImmutable;

final readonly class OAuthTokens
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public CarbonImmutable $expiresAt,
    ) {}
}
