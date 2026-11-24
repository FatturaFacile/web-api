<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use App\Models\Tenant;
use App\Models\User;

/**
 * A single sign-on that passed every check: the user and the tenant of the provider, and the hash of the browser binding
 * of the login that produced it (see SsoBrowserBinding), still to be matched against the browser.
 */
final readonly class SsoCompletion
{
    public function __construct(
        public User $user,
        public Tenant $tenant,
        public ?string $bindingHash,
    ) {}
}
