<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Services;

use Enterprise\AuditLedger\Domain\ValueObjects\PseudonymToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pseudonymisation for the right to erasure (GDPR art. 17, nLPD, Mod 096). The chain only ever holds a token; the readable
 * value is held, encrypted, in `audit_subject_map` for as long as the data subject has not been erased. Deleting the
 * mapping rows leaves every hash untouched (the chain stays valid) and turns the display into the erased label.
 */
final class Pseudonymizer
{
    public const string ERASED_LABEL = '[erased subject]';

    public const string TABLE = 'audit_subject_map';

    public function __construct(private readonly AuditKeyring $keyring) {}

    /**
     * The deterministic token of a value of a subject: the same value of the same subject always gets the same token.
     */
    public function tokenFor(string $tenantId, string $subjectType, string $subjectId, string $value, string $field = ''): PseudonymToken
    {
        $key = $this->keyring->pseudonymKey($tenantId, $this->keyring->activeVersion($tenantId));

        return PseudonymToken::fromDigest(hash_hmac('sha256', implode("\x1F", [$subjectType, $subjectId, $field, $value]), $key));
    }

    /**
     * The token of a person (actor of an entry, impersonator): stable for the person, whatever their display name is.
     */
    public function subjectToken(string $tenantId, string $subjectType, string $subjectId, string $displayName): PseudonymToken
    {
        $token = $this->tokenFor($tenantId, $subjectType, $subjectId, '', 'subject');
        $this->remember($token, $tenantId, $subjectType, $subjectId, $displayName);

        return $token;
    }

    /**
     * The token of a personal value of a record, remembered so that it can be shown until the subject is erased.
     */
    public function valueToken(string $tenantId, string $subjectType, string $subjectId, string $field, string $value): PseudonymToken
    {
        $token = $this->tokenFor($tenantId, $subjectType, $subjectId, $value, $field);
        $this->remember($token, $tenantId, $subjectType, $subjectId, $value);

        return $token;
    }

    public function remember(PseudonymToken $token, string $tenantId, string $subjectType, string $subjectId, string $displayName): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'token' => $token->value,
            'tenant_id' => $tenantId,
            'subject_type' => substr($subjectType, 0, 120),
            'subject_id' => substr($subjectId, 0, 64),
            'display_name' => Crypt::encryptString($displayName),
            'created_at' => Carbon::now('UTC')->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /**
     * Deletes every mapping of the subject. Returns how many tokens can no longer be resolved.
     */
    public function erase(string $tenantId, string $subjectType, string $subjectId): int
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('subject_type', substr($subjectType, 0, 120))
            ->where('subject_id', substr($subjectId, 0, 64))
            ->delete();
    }

    /**
     * Readable values of tokens: token => display name, or the erased label when the mapping is gone.
     *
     * @param  list<string>  $tokens
     * @return array<string, string>
     */
    public function resolveMany(string $tenantId, array $tokens): array
    {
        $tokens = array_values(array_unique(array_filter($tokens, static fn (mixed $token): bool => PseudonymToken::looksLikeToken($token))));

        if ($tokens === []) {
            return [];
        }

        $resolved = array_fill_keys($tokens, self::ERASED_LABEL);

        foreach (array_chunk($tokens, 500) as $chunk) {
            $rows = DB::table(self::TABLE)->where('tenant_id', $tenantId)->whereIn('token', $chunk)->get(['token', 'display_name']);

            foreach ($rows as $row) {
                try {
                    $resolved[$row->token] = Crypt::decryptString((string) $row->display_name);
                } catch (Throwable) {
                    $resolved[$row->token] = self::ERASED_LABEL;
                }
            }
        }

        return $resolved;
    }

    /**
     * Collects the tokens present in a nested document.
     *
     * @return list<string>
     */
    public static function tokensIn(mixed $data): array
    {
        if (PseudonymToken::looksLikeToken($data)) {
            return [(string) $data];
        }

        if (! is_array($data)) {
            return [];
        }

        $found = [];

        foreach ($data as $item) {
            array_push($found, ...self::tokensIn($item));
        }

        return $found;
    }

    /**
     * The document with every token replaced by its readable value (or the erased label).
     *
     * @param  array<string, string>  $resolved  from resolveMany()
     */
    public static function applyTo(mixed $data, array $resolved): mixed
    {
        if (PseudonymToken::looksLikeToken($data)) {
            return $resolved[(string) $data] ?? self::ERASED_LABEL;
        }

        if (! is_array($data)) {
            return $data;
        }

        return array_map(static fn (mixed $item): mixed => self::applyTo($item, $resolved), $data);
    }
}
