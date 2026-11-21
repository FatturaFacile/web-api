<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\ValueObjects;

use Modules\Platform\Identity\Domain\Exceptions\InvalidPermissionKey;

/** A permission identifier shaped `<module>.<resource>.<action>`, all lowercase snake_case. */
final readonly class PermissionKey
{
    public const STANDARD_ACTIONS = ['view', 'create', 'update', 'delete', 'export'];

    public const DOMAIN_ACTIONS = ['approve', 'post', 'cancel', 'send_sdi', 'view_sensitive'];

    private function __construct(
        public string $module,
        public string $resource,
        public string $action,
    ) {}

    public static function from(string $key): self
    {
        $parts = explode('.', $key);

        if (count($parts) !== 3) {
            throw new InvalidPermissionKey("Permission key [{$key}] must be <module>.<resource>.<action>.");
        }

        foreach ($parts as $part) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $part) !== 1) {
                throw new InvalidPermissionKey("Permission key [{$key}] contains an invalid segment.");
            }
        }

        if (! in_array($parts[2], [...self::STANDARD_ACTIONS, ...self::DOMAIN_ACTIONS], true)) {
            throw new InvalidPermissionKey("Permission key [{$key}] uses an unknown action [{$parts[2]}].");
        }

        return new self($parts[0], $parts[1], $parts[2]);
    }

    public static function isValid(string $key): bool
    {
        try {
            self::from($key);

            return true;
        } catch (InvalidPermissionKey) {
            return false;
        }
    }

    public function value(): string
    {
        return "{$this->module}.{$this->resource}.{$this->action}";
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
