<?php

declare(strict_types=1);

namespace Modules\Marketplace\Support;

use InvalidArgumentException;

/**
 * The catalog entry of a connector: what it is, which version, which category, which settings and credentials it needs,
 * which scopes of the company it requires (as `<module>.<read|write>`), which resources it synchronises and how many calls
 * a minute the external system tolerates.
 */
final readonly class ConnectorDefinition
{
    public const string Ecommerce = 'ecommerce';

    public const string Shipping = 'shipping';

    public const string Payment = 'payment';

    public const string AccountingExport = 'accounting_export';

    public const string Automation = 'automation';

    /** @var list<string> */
    public const array Categories = [self::Ecommerce, self::Shipping, self::Payment, self::AccountingExport, self::Automation];

    /**
     * @param  list<ConfigField>  $fields
     * @param  list<string>  $requiredScopes
     * @param  list<string>  $resources
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $category,
        public string $version,
        public array $fields,
        public array $requiredScopes,
        public array $resources,
        public int $rateLimitPerMinute = 60,
        public int $maxRateLimitPerMinute = 120,
        public ?string $vendor = null,
        public ?string $documentationUrl = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,58}$/', $key) !== 1 || ! in_array($category, self::Categories, true) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new InvalidArgumentException("Invalid connector definition [{$key}].");
        }

        if ($resources === [] || array_any($resources, static fn (string $resource): bool => preg_match('/^[a-z][a-z0-9_]{0,59}$/', $resource) !== 1)) {
            throw new InvalidArgumentException("Connector [{$key}] needs valid resources.");
        }

        if ($rateLimitPerMinute < 1 || $maxRateLimitPerMinute < $rateLimitPerMinute) {
            throw new InvalidArgumentException("Connector [{$key}] has an invalid rate limit.");
        }

        if (array_any($requiredScopes, static fn (string $scope): bool => preg_match('/^[a-z][a-z0-9_]*\.(read|write)$/', $scope) !== 1)) {
            throw new InvalidArgumentException("Connector [{$key}] has an invalid scope.");
        }

        $keys = array_map(static fn (ConfigField $field): string => $field->key, $fields);

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException("Connector [{$key}] repeats a configuration field.");
        }
    }

    public function field(string $key): ?ConfigField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<ConfigField>
     */
    public function secretFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ConfigField $field): bool => $field->isSecret()));
    }

    /**
     * @return list<ConfigField>
     */
    public function settingFields(): array
    {
        return array_values(array_filter($this->fields, static fn (ConfigField $field): bool => ! $field->isSecret()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'version' => $this->version,
            'vendor' => $this->vendor,
            'documentation_url' => $this->documentationUrl,
            'config_schema' => array_map(static fn (ConfigField $field): array => $field->toArray(), $this->fields),
            'required_scopes' => $this->requiredScopes,
            'resources' => $this->resources,
            'rate_limit_per_minute' => $this->rateLimitPerMinute,
            'max_rate_limit_per_minute' => $this->maxRateLimitPerMinute,
        ];
    }
}
