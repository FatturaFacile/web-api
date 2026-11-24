<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\DTOs;

use Carbon\CarbonImmutable;

/**
 * Immutable data carrier: oauth tokens.
 */
final readonly class OAuthTokens
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public CarbonImmutable $expiresAt,
    ) {}
}
