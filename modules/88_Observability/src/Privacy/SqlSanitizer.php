<?php

declare(strict_types=1);

namespace Modules\Observability\Privacy;

/**
 * Turns an SQL statement into its literal-free shape: strings, numbers, parameters and value lists become "?", so a fingerprint groups the same
 * query and nothing a customer typed (names, IBANs, amounts) can leave the database layer. Bindings are never stored.
 */
final class SqlSanitizer
{
    public function normalize(string $sql): string
    {
        $sql = (string) preg_replace('/\/\*.*?\*\//s', ' ', $sql);
        $sql = (string) preg_replace('/--[^\r\n]*/', ' ', $sql);
        $sql = (string) preg_replace("/(?:\\b[eEnNbBxX])?'(?:[^']|'')*'/", '?', $sql);
        $sql = (string) preg_replace('/\$(\w*)\$.*?\$\1\$/s', '?', $sql);
        $sql = (string) preg_replace('/\$\d+/', '?', $sql);
        $sql = (string) preg_replace('/(?<![\w."])-?\b\d+(?:\.\d+)?(?:e[+-]?\d+)?\b(?![\w"])/i', '?', $sql);
        $sql = (string) preg_replace('/\b(in|not in)\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', '$1 (?)', $sql);
        $sql = (string) preg_replace('/\bvalues\s*\(\s*\?(?:\s*,\s*\?)*\s*\)(?:\s*,\s*\(\s*\?(?:\s*,\s*\?)*\s*\))+/i', 'values (?)', $sql);
        $sql = (string) preg_replace('/\s+/', ' ', $sql);

        return rtrim(trim($sql), ';');
    }

    public function fingerprint(string $normalizedSql): string
    {
        return substr(sha1(strtolower($normalizedSql)), 0, 40);
    }

    public function isSelect(string $sql): bool
    {
        return preg_match('/^\s*(?:\(\s*)*(select|with)\b/i', $sql) === 1;
    }

    /**
     * Filters, join and index conditions of a plan carry the literals of the query: they are reduced to "?" as well.
     *
     * @param  array<array-key, mixed>  $plan
     * @return array<array-key, mixed>
     */
    public function sanitizePlan(array $plan): array
    {
        foreach ($plan as $key => $value) {
            if (is_array($value)) {
                $plan[$key] = $this->sanitizePlan($value);

                continue;
            }

            if (is_string($key) && is_string($value) && preg_match('/(Filter|Cond|Check)$/', $key) === 1) {
                $plan[$key] = $this->normalize($value);
            }
        }

        return $plan;
    }
}
