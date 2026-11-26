<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Support;

/**
 * Maps the short resource type of the API ("Invoice") to the class names the ledger holds ("App\Models\Invoice").
 */
final class ResourceTypes
{
    /**
     * @return list<string>
     */
    public static function candidates(string $type): array
    {
        $type = trim($type);

        if (str_contains($type, '\\')) {
            return [substr($type, 0, 60)];
        }

        $configured = config('audit_ledger.history_resources.'.$type);
        $candidates = [$type];

        if (is_array($configured) && isset($configured['class'])) {
            $candidates[] = substr((string) $configured['class'], 0, 60);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * The module key a member needs (read access) to see the history of a record, or null when the type is not exposed.
     */
    public static function module(string $type): ?string
    {
        $type = trim($type);

        if (str_contains($type, '\\')) {
            foreach ((array) config('audit_ledger.history_resources', []) as $configured) {
                if (is_array($configured) && ($configured['class'] ?? null) === $type) {
                    return (string) $configured['module'];
                }
            }

            return null;
        }

        $configured = config('audit_ledger.history_resources.'.$type);

        return is_array($configured) ? (string) $configured['module'] : null;
    }
}
