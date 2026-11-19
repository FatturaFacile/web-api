<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum AuditEventType: string
{
    case Created = 'CREATED';
    case Updated = 'UPDATED';
    case Deleted = 'DELETED';
    case Restored = 'RESTORED';
    case Viewed = 'VIEWED';
    case Exported = 'EXPORTED';
    case LoginSuccess = 'LOGIN_SUCCESS';
    case LoginFailed = 'LOGIN_FAILED';
    case PermissionChanged = 'PERMISSION_CHANGED';
    case WormSealed = 'WORM_SEALED';
    case AuditLogInspected = 'AUDIT_LOG_INSPECTED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
