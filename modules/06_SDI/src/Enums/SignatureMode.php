<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

enum SignatureMode: string
{
    case None = 'none';
    case LocalPkcs12 = 'local_pkcs12';
    case RemoteHsmProvider = 'remote_hsm_provider';
}
