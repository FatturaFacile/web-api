<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum CertificateKind: string
{
    case NonCompetitive = 'non_competitive';
    case Competitive = 'competitive';

    /**
     * A competitive certificate also covers what asks for a non-competitive one, never the other way round.
     *
     * @return list<string>
     */
    public function acceptedValues(): array
    {
        return $this === self::Competitive ? [self::Competitive->value] : [self::Competitive->value, self::NonCompetitive->value];
    }
}
