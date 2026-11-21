<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Enums;

enum AuthMethod: string
{
    case Password = 'password';
    case Passkey = 'passkey';
    case Totp = 'totp';
    case Oidc = 'oidc';
    case Saml = 'saml';
}
