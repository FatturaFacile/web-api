<?php

declare(strict_types=1);

namespace Modules\Assistant\DTOs;

/**
 * Immutable data carrier: resolved intent.
 */
final readonly class ResolvedIntent
{
    /**
     * @param  array<string, mixed>  $parameters  raw values, validated against the catalogue before use
     */
    public function __construct(
        public string $queryKey,
        public array $parameters = [],
    ) {}
}
