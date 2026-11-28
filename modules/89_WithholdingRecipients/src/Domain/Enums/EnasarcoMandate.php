<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\Enums;

enum EnasarcoMandate: string
{
    case Mono = 'mono';
    case Pluri = 'pluri';
}
