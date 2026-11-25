<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Enums;

/**
 * Lifecycle of a dossier: draft -> reclassified -> notes_populated -> validated -> locked -> filed.
 */
enum DossierStatus: string
{
    case Draft = 'draft';
    case Reclassified = 'reclassified';
    case NotesPopulated = 'notes_populated';
    case Validated = 'validated';
    case Locked = 'locked';
    case Filed = 'filed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }

    /**
     * Whether the figures of the statements can still be regenerated.
     */
    public function isEditable(): bool
    {
        return $this->rank() < self::Locked->rank();
    }

    /**
     * Manual transitions: lock a validated dossier, file a locked one, reopen a locked (never a filed) one.
     *
     * @return list<self>
     */
    public function manualTargets(): array
    {
        return match ($this) {
            self::Validated => [self::Locked],
            self::Locked => [self::Filed, self::Draft],
            default => [],
        };
    }
}
