<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Services;

/**
 * What may enter the chain. Secrets (passwords, tokens, API keys, OTP codes...) are replaced by a marker and never
 * recorded, not even pseudonymised; personal attributes are replaced by a pseudonym token (see Pseudonymizer).
 *
 * Names are matched exactly (after lower casing and turning dashes into underscores) or by an explicit prefix/suffix
 * list: a substring match would hit innocent attributes such as `shipping_address` (`pin`) or `mapping_id` (`pin`).
 */
final class PayloadSanitizer
{
    public const string REDACTED = '[REDACTED]';

    /**
     * @param  list<string>  $secretKeys
     * @param  list<string>  $secretPrefixes
     * @param  list<string>  $secretSuffixes
     * @param  list<string>  $personalKeys
     */
    public function __construct(
        private readonly Pseudonymizer $pseudonymizer,
        private readonly array $secretKeys,
        private readonly array $secretPrefixes,
        private readonly array $secretSuffixes,
        private readonly array $personalKeys,
    ) {}

    public static function fromConfig(Pseudonymizer $pseudonymizer): self
    {
        return new self(
            $pseudonymizer,
            array_map(self::normalizeKey(...), (array) config('audit_ledger.secret_keys', [])),
            array_map(self::normalizeKey(...), (array) config('audit_ledger.secret_prefixes', [])),
            array_map(self::normalizeKey(...), (array) config('audit_ledger.secret_suffixes', [])),
            array_map(self::normalizeKey(...), (array) config('audit_ledger.personal_keys', [])),
        );
    }

    public static function normalizeKey(string $key): string
    {
        return str_replace('-', '_', strtolower(trim($key)));
    }

    public function isSecret(string $key): bool
    {
        $normalized = self::normalizeKey($key);

        if (in_array($normalized, $this->secretKeys, true)) {
            return true;
        }

        foreach ($this->secretPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        foreach ($this->secretSuffixes as $suffix) {
            if ($suffix !== '' && str_ends_with($normalized, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $extraPersonal  the $auditPersonal attributes of the model
     */
    public function isPersonal(string $key, array $extraPersonal = []): bool
    {
        $normalized = self::normalizeKey($key);

        return in_array($normalized, $this->personalKeys, true) || in_array($normalized, array_map(self::normalizeKey(...), $extraPersonal), true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $extraPersonal
     * @return array<string, mixed>
     */
    public function sanitize(string $tenantId, string $subjectType, string $subjectId, array $payload, array $extraPersonal = []): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $name = (string) $key;

            if ($this->isSecret($name)) {
                $result[$key] = self::REDACTED;
            } elseif ($this->isPersonal($name, $extraPersonal) && $value !== null && $value !== '' && (is_scalar($value) || $value instanceof \Stringable)) {
                $result[$key] = (string) $this->pseudonymizer->valueToken($tenantId, $subjectType, $subjectId, $name, (string) $value);
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitize($tenantId, $subjectType, $subjectId, $value, $extraPersonal);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Only the secrets, for payloads that are not tied to a subject.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withoutSecrets(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if ($this->isSecret((string) $key)) {
                $result[$key] = self::REDACTED;
            } else {
                $result[$key] = is_array($value) ? $this->withoutSecrets($value) : $value;
            }
        }

        return $result;
    }
}
