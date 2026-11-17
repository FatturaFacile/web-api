<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

/**
 * The way a company talks to the customs system. `passar` and `aida` are sandbox channels: they build the payload and
 * simulate the answer, nothing reaches BAZG or ADM.
 */
enum CustomsChannel: string
{
    case Local = 'local';
    case Edec = 'edec';
    case Passar = 'passar';
    case Aida = 'aida';

    public function regime(): ?CustomsRegime
    {
        return match ($this) {
            self::Local => null,
            self::Edec => CustomsRegime::Edec,
            self::Passar => CustomsRegime::Passar,
            self::Aida => CustomsRegime::Aida,
        };
    }
}
