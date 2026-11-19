<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The cryptography of the audit trail: canonical JSON, the SHA-256 hash chain, the HMAC-SHA256 record signature, the
 * Merkle root over a range of records and the AES-256-GCM encryption of the old/new values at rest.
 */
final class AuditChainCryptoService
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    /**
     * Recursively key-sorted JSON (lists keep their order), the same bytes for the same data on every run.
     */
    public function canonicalJson(mixed $value): string
    {
        return (string) json_encode($this->canonicalise($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array<string, mixed>  $fields  the fields covered by the hash (see hashableFields())
     */
    public function computeHash(string $previousHash, array $fields): string
    {
        return hash('sha256', $previousHash.'|'.$this->canonicalJson($fields));
    }

    public function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->hmacKey());
    }

    public function verifySignature(string $data, string $signature): bool
    {
        return hash_equals($this->sign($data), $signature);
    }

    /**
     * The fields of a stored record that the hash covers: everything but the hash, the signature and the primary key.
     *
     * @return array<string, mixed>
     */
    public function hashableFields(AuditLog $log): array
    {
        return [
            'tenant_id' => $log->tenant_id,
            'sequence_number' => $log->sequence_number,
            'event_type' => $log->event_type,
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'actor_type' => $log->actor_type,
            'actor_id' => $log->actor_id,
            'actor_email' => $log->actor_email,
            'actor_role' => $log->actor_role,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'request_id' => $log->request_id,
            'route_name' => $log->route_name,
            'old_values_encrypted' => $log->old_values_encrypted,
            'new_values_encrypted' => $log->new_values_encrypted,
            'changed_fields' => $log->changed_fields,
            'context_metadata' => $log->context_metadata,
            'occurred_at' => $this->normaliseTimestamp($log->occurred_at),
        ];
    }

    /**
     * The timestamp as written into the hash: UTC with microseconds, whatever format the database returns it in.
     */
    public function normaliseTimestamp(string $value): string
    {
        return Carbon::parse($value, 'UTC')->setTimezone('UTC')->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @param  list<string>  $hashes
     */
    public function merkleRoot(array $hashes): ?string
    {
        if ($hashes === []) {
            return null;
        }

        $level = $hashes;

        while (count($level) > 1) {
            $next = [];

            foreach (array_chunk($level, 2) as $pair) {
                $next[] = hash('sha256', $pair[0].($pair[1] ?? $pair[0]));
            }

            $level = $next;
        }

        return $level[0];
    }

    /**
     * @param  array<string, mixed>|null  $values
     */
    public function encrypt(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt($this->canonicalJson($values), self::CIPHER, $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if ($cipher === false) {
            throw new RuntimeException('The audit payload could not be encrypted.');
        }

        return base64_encode($iv.$tag.$cipher);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decrypt(?string $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('The audit payload is not a valid ciphertext.');
        }

        $plain = openssl_decrypt(
            substr($raw, self::IV_LENGTH + self::TAG_LENGTH),
            self::CIPHER,
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LENGTH),
            substr($raw, self::IV_LENGTH, self::TAG_LENGTH),
        );

        if ($plain === false) {
            throw new RuntimeException('The audit payload failed authentication: it was altered or the key is wrong.');
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function hmacKey(): string
    {
        return $this->derive(config('audit.hmac_secret'), 'audit-hmac');
    }

    private function encryptionKey(): string
    {
        return $this->derive(config('audit.encryption_key'), 'audit-encryption');
    }

    private function derive(mixed $configured, string $purpose): string
    {
        $secret = is_string($configured) && $configured !== '' ? $configured : (string) config('app.key');

        if ($secret === '') {
            throw new RuntimeException('No secret is configured for the audit trail (AUDIT_HMAC_SECRET, AUDIT_ENCRYPTION_KEY or APP_KEY).');
        }

        return hash_hmac('sha256', $purpose, $secret, true);
    }

    private function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item): mixed => $this->canonicalise($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
