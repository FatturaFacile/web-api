<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Enums;

/**
 * How the document behind an open item reached the system.
 */
enum OpenItemSource: string
{
    case ElectronicXml = 'electronic_xml';
    case SwissQr = 'swiss_qr';
    case Manual = 'manual';
}
