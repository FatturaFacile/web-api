<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Enums;

enum RecipientRole: string
{
    case Signer = 'SIGNER';
    case Approver = 'APPROVER';
    case Witness = 'WITNESS';
    case CcRecipient = 'CC_RECIPIENT';

    /**
     * Whether the role has to act (sign, approve or witness); a copy recipient only receives the signed document.
     */
    public function acts(): bool
    {
        return $this !== self::CcRecipient;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
