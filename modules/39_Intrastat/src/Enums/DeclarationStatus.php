<?php

declare(strict_types=1);

namespace Modules\Intrastat\Enums;

enum DeclarationStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Submitted = 'submitted';

    /**
     * A submitted list is never changed: corrections go into the correction sections of a later list.
     */
    public function isEditable(): bool
    {
        return $this !== self::Submitted;
    }
}
