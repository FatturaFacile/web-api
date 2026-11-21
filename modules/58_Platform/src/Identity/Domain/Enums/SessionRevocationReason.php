<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Enums;

enum SessionRevocationReason: string
{
    case UserRequested = 'user_requested';
    case AdminRevoked = 'admin_revoked';
    case UserDeactivated = 'user_deactivated';
    case PasswordChanged = 'password_changed';
    case SsoDeprovisioned = 'sso_deprovisioned';
    case RoleChanged = 'role_changed';
}
