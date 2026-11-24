<?php

declare(strict_types=1);

namespace Modules\Marketplace\Support;

/**
 * What a connector receives to talk to the external system: the settings and the decrypted credentials of one
 * installation. It is built for one call and thrown away; it never reveals the credentials when it is dumped.
 */
final readonly class ConnectorContext
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        public string $tenantId,
        public string $installationId,
        public string $connectorKey,
        public array $settings,
        private array $credentials,
    ) {}

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Removes every credential value from a text (an error message of the external system, a response excerpt) before it
     * is stored or shown.
     */
    public function redact(string $text): string
    {
        foreach ($this->credentials as $value) {
            if (strlen($value) >= 4) {
                $text = str_replace($value, '***', $text);
            }
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['tenantId' => $this->tenantId, 'installationId' => $this->installationId, 'connectorKey' => $this->connectorKey, 'settings' => $this->settings, 'credentials' => array_keys($this->credentials)];
    }
}
