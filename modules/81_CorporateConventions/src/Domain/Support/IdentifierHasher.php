<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Support;

/**
 * SHA-256 of an employee badge, matricola or membership card. The employer list is stored only as hashes (GDPR / nLPD): the
 * identifier is trimmed, stripped of inner whitespace and upper-cased first so "ab 123" and "AB123" are the same person.
 */
final class IdentifierHasher
{
    public static function normalize(string $identifier): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', trim($identifier)));
    }

    public static function hash(string $identifier): string
    {
        return hash('sha256', self::normalize($identifier));
    }

    /**
     * Last characters of an identifier, to recognise a row without storing it ("****1234").
     */
    public static function hint(string $identifier, int $visible = 3): string
    {
        $normalized = self::normalize($identifier);

        return mb_strlen($normalized) <= $visible ? str_repeat('*', mb_strlen($normalized)) : '***'.mb_substr($normalized, -$visible);
    }
}
