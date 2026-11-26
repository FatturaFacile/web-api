<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Support;

use Illuminate\Support\Str;

/**
 * Deterministic variant SKUs: `<PARENT>-<TOKEN>-<TOKEN>` with the tokens of the attribute values in the order of the matrix axes.
 * Collisions get a numeric suffix (`-2`, `-3`, ...) so the same matrix always produces the same codes.
 */
final class SkuBuilder
{
    public const int MAX_LENGTH = 50;

    /**
     * @param  list<string>  $tokens
     * @param  array<string, true>  $taken  SKUs already in use (upper case keys)
     */
    public static function build(string $parentSku, array $tokens, array $taken = []): string
    {
        $parts = array_filter(
            [strtoupper(preg_replace('#[^A-Za-z0-9._/-]+#', '', Str::ascii($parentSku)) ?? ''), ...array_map(static fn (string $token): string => self::clean($token), $tokens)],
            static fn (string $part): bool => $part !== '',
        );
        $base = substr(implode('-', $parts), 0, self::MAX_LENGTH);
        $candidate = $base;
        $n = 1;

        while (isset($taken[strtoupper($candidate)])) {
            $n++;
            $suffix = '-'.$n;
            $candidate = substr($base, 0, self::MAX_LENGTH - strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /**
     * A value reduced to the characters allowed in a SKU token: upper case letters and digits.
     */
    public static function clean(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', Str::ascii($value)) ?? '');
    }

    /**
     * The default token of a label: its first letters.
     */
    public static function tokenOf(string $label, int $length = 3): string
    {
        return substr(self::clean($label), 0, $length);
    }
}
