<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\ValueObjects;

use Modules\Platform\Localization\Domain\Exceptions\InvalidTranslationKeyException;

/**
 * A translation key of the form group.segment[.segment...]: the first segment is the group the keys are filtered by.
 */
final readonly class TranslationKey
{
    private const string PATTERN = '/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)+$/';

    private const int MAX_LENGTH = 255;

    private function __construct(private string $value) {}

    public static function from(string $value): self
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidTranslationKeyException::malformed($value);
        }

        return new self($value);
    }

    public static function fromParts(string $group, string $key): self
    {
        return self::from($group.'.'.$key);
    }

    public static function isValid(string $value): bool
    {
        return strlen($value) <= self::MAX_LENGTH && preg_match(self::PATTERN, $value) === 1;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function group(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    /**
     * The key without its group.
     */
    public function name(): string
    {
        return explode('.', $this->value, 2)[1];
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
