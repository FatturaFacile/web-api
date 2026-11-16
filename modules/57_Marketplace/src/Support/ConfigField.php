<?php

declare(strict_types=1);

namespace Modules\Marketplace\Support;

use InvalidArgumentException;

/**
 * One field of the configuration schema of a connector. A `secret` field is a credential: it is stored encrypted, never
 * returned by the API and never shown again after it is saved.
 */
final readonly class ConfigField
{
    public const array Types = ['string', 'url', 'secret', 'number', 'boolean', 'select'];

    /**
     * @param  list<string>  $options  allowed values of a `select` field
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'string',
        public bool $required = true,
        public array $options = [],
        public string|int|bool|null $default = null,
        public ?string $help = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1 || ! in_array($type, self::Types, true) || ($type === 'select' && $options === [])) {
            throw new InvalidArgumentException("Invalid configuration field [{$key}].");
        }
    }

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'options' => $this->options,
            'default' => $this->default,
            'help' => $this->help,
        ];
    }
}
