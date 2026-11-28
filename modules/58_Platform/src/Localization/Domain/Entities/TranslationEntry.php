<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Entities;

use Modules\Platform\Localization\Domain\ValueObjects\TranslationKey;

/**
 * One translated string of a catalog.
 */
final class TranslationEntry
{
    public function __construct(
        public readonly TranslationKey $key,
        private string $value,
        public readonly bool $isLocked = false,
        public readonly bool $isDeprecated = false,
    ) {}

    public function group(): string
    {
        return $this->key->group();
    }

    public function value(): string
    {
        return $this->value;
    }

    public function withValue(string $value): self
    {
        return new self($this->key, $value, $this->isLocked, $this->isDeprecated);
    }

    public function markDeprecated(): self
    {
        return new self($this->key, $this->value, $this->isLocked, true);
    }
}
