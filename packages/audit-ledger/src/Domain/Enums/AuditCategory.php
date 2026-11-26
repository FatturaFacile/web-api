<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Enums;

/**
 * What kind of fact an entry proves. Drives the retention period and the filters of the search.
 */
enum AuditCategory: string
{
    /** Create, update, delete and restore of a record (Auditable trait). */
    case Crud = 'crud';
    /** Business state transitions: issuance, SDI, booking, cancellation, approvals. */
    case Business = 'business';
    /** Authentication and authorisation events (Mod 004). */
    case Security = 'security';
    /** Reads of sensitive data, restricted downloads, exports and printing. */
    case Access = 'access';
    /** Backup, restore, configuration changes and integrity verification. */
    case Platform = 'platform';

    /**
     * The category of an entry written without one, from the module code and the action.
     */
    public static function infer(string $action, string $moduleCode): self
    {
        $action = strtoupper($action);
        $module = strtoupper($moduleCode);

        if (in_array($module, ['AUTH', 'ACCESS', 'IDENTITY'], true) || str_starts_with($action, 'LOGIN') || str_starts_with($action, 'LOGOUT') || str_contains($action, 'SECURITY')) {
            return self::Security;
        }

        if (in_array($action, ['VIEWED', 'EXPORTED', 'DOWNLOADED', 'PRINTED', 'SENSITIVE_VIEWED'], true) || str_ends_with($action, '_VIEWED') || str_ends_with($action, '_EXPORTED') || str_ends_with($action, '_DOWNLOADED')) {
            return self::Access;
        }

        if (in_array($module, ['SYSTEM', 'PLATFORM', 'AUDIT'], true) || str_starts_with($action, 'TENANT_') || str_starts_with($action, 'AUDIT_') || str_starts_with($action, 'BACKUP')) {
            return self::Platform;
        }

        if (in_array($action, ['CREATED', 'UPDATED', 'DELETED', 'RESTORED'], true)) {
            return self::Crud;
        }

        return self::Business;
    }
}
