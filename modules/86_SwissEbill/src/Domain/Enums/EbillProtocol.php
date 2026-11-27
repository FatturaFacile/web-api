<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Enums;

enum EbillProtocol: string
{
    case RestJsonXml = 'rest_json_xml';
    case SoapEbinterface = 'soap_ebinterface';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
