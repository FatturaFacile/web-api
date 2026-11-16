<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Illuminate\Validation\ValidationException;
use Modules\Integrations\Webhooks\Exceptions\UnsafeWebhookUrl;
use Modules\Integrations\Webhooks\WebhookUrlGuard;
use Modules\Marketplace\Support\ConfigField;
use Modules\Marketplace\Support\ConnectorDefinition;

/**
 * Validates the configuration of an installation against the schema of its connector: only the fields of the schema,
 * required ones present, every value of the type of its field. Address fields go through the same guard as webhook
 * endpoints (https, public addresses). Settings and secrets are separated here: a secret that is not sent again keeps
 * its stored value, so a screen never has to know it.
 */
final readonly class ConfigurationValidator
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private WebhookUrlGuard $urls) {}

    /**
     * @param  array<string, mixed>  $input  field key => value, settings and secrets together
     * @param  array<string, string>  $storedCredentials  credentials already saved, kept when the input leaves them out
     * @return array{settings: array<string, mixed>, credentials: array<string, string>}
     *
     * @throws ValidationException
     */
    public function validate(ConnectorDefinition $definition, array $input, array $storedCredentials = []): array
    {
        $errors = [];
        $settings = [];
        $credentials = [];

        foreach (array_keys($input) as $key) {
            if ($definition->field((string) $key) === null) {
                $errors["config.{$key}"][] = __('marketplace_errors.config.unknown_field');
            }
        }

        foreach ($definition->fields as $field) {
            $given = array_key_exists($field->key, $input);
            $value = $given ? $input[$field->key] : ($field->isSecret() ? ($storedCredentials[$field->key] ?? null) : $field->default);

            if ($value === null || $value === '') {
                if ($field->required) {
                    $errors["config.{$field->key}"][] = __('marketplace_errors.config.required', ['field' => $field->label]);
                }

                continue;
            }

            $error = $given || $field->isSecret() === false ? $this->check($field, $value) : null;

            if ($error !== null) {
                $errors["config.{$field->key}"][] = $error;

                continue;
            }

            if ($field->isSecret()) {
                $credentials[$field->key] = (string) $value;
            } else {
                $settings[$field->key] = $field->type === 'number' ? $value + 0 : ($field->type === 'boolean' ? (bool) $value : (string) $value);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['settings' => $settings, 'credentials' => $credentials];
    }

    /**
     * Whether a stored configuration still satisfies a schema (after a connector upgrade).
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $credentials
     */
    public function isComplete(ConnectorDefinition $definition, array $settings, array $credentials): bool
    {
        foreach ($definition->fields as $field) {
            $value = $field->isSecret() ? ($credentials[$field->key] ?? null) : ($settings[$field->key] ?? null);

            if ($field->required && ($value === null || $value === '')) {
                return false;
            }
        }

        return true;
    }

    private function check(ConfigField $field, mixed $value): ?string
    {
        return match ($field->type) {
            'string' => is_string($value) && mb_strlen($value) <= 255 ? null : __('marketplace_errors.config.invalid_text', ['field' => $field->label]),
            'secret' => is_string($value) && strlen($value) >= 4 && strlen($value) <= 2048 ? null : __('marketplace_errors.config.invalid_secret', ['field' => $field->label]),
            'number' => is_numeric($value) ? null : __('marketplace_errors.config.invalid_number', ['field' => $field->label]),
            'boolean' => is_bool($value) ? null : __('marketplace_errors.config.invalid_boolean', ['field' => $field->label]),
            'select' => in_array($value, $field->options, true) ? null : __('marketplace_errors.config.invalid_option', ['field' => $field->label]),
            'url' => $this->checkUrl($field, $value),
            default => __('marketplace_errors.config.unknown_field'),
        };
    }

    private function checkUrl(ConfigField $field, mixed $value): ?string
    {
        if (! is_string($value)) {
            return __('marketplace_errors.config.invalid_url', ['field' => $field->label]);
        }

        try {
            $this->urls->assertSafe($value);
        } catch (UnsafeWebhookUrl) {
            return __('marketplace_errors.config.invalid_url', ['field' => $field->label]);
        }

        return null;
    }
}
