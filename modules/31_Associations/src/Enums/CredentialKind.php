<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum CredentialKind: string
{
    case Qr = 'qr';
    case Badge = 'badge';
}
