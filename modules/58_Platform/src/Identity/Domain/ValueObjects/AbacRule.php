<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\ValueObjects;

/** A conditional rule attached to a permission: an `allow` rule restricts the grant, a `deny` rule overrides it. */
final readonly class AbacRule
{
    /**
     * @param  list<AbacCondition>  $conditions  all must match for the rule to apply (logical AND)
     */
    public function __construct(
        public string $permission,
        public string $effect,
        public array $conditions,
    ) {}

    public function isDeny(): bool
    {
        return $this->effect === 'deny';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function matches(array $context): bool
    {
        if ($this->conditions === []) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (! $condition->matches($context)) {
                return false;
            }
        }

        return true;
    }
}
