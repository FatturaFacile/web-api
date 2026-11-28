<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum SianStatus: string
{
    case Draft = 'DRAFT';
    case Validated = 'VALIDATED';
    case Exported = 'EXPORTED';
    case TransmittedSian = 'TRANSMITTED_SIAN';
    case AckOk = 'ACK_OK';
    case AckError = 'ACK_ERROR';

    /**
     * Operations still to be sent: the six day deadline runs against them.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::Draft, self::Validated, self::Exported, self::AckError], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
