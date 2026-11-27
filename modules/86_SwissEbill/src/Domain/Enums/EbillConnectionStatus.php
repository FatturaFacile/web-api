<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Enums;

enum EbillConnectionStatus: string
{
    case Untested = 'untested';
    case Connected = 'connected';
    case Failed = 'failed';
}
