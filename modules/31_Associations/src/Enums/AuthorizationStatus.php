<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum AuthorizationStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Revoked = 'revoked';
}
