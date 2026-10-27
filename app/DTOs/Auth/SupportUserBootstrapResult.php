<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Models\User;

final readonly class SupportUserBootstrapResult
{
    public function __construct(
        public bool $created,
        public User $user,
        public bool $emailDispatched,
        public string $message,
    ) {}
}
