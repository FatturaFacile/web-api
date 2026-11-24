<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use Illuminate\Validation\ValidationException;

/**
 * Checks a change of status against the transition map of a record, shared by every state machine of the module.
 */
final class StatusMachine
{
    /**
     * @param  array<string, list<string>>  $transitions  from => list of targets
     *
     * @throws ValidationException when the target is unknown or not reachable from the current status
     */
    public static function assertAllowed(array $transitions, string $from, string $to): void
    {
        if (! array_key_exists($to, $transitions)) {
            throw ValidationException::withMessages(['status' => [__('governance_errors.status.unknown', ['status' => $to])]]);
        }

        if (! in_array($to, $transitions[$from] ?? [], true)) {
            throw ValidationException::withMessages(['status' => [__('governance_errors.status.not_allowed', [
                'from' => $from,
                'to' => $to,
                'allowed' => implode(' / ', $transitions[$from] ?? []) ?: '-',
            ])]]);
        }
    }

    /**
     * @throws ValidationException when the notes are empty
     */
    public static function requireNotes(?string $notes, string $messageKey): string
    {
        if ($notes === null || trim($notes) === '') {
            throw ValidationException::withMessages(['notes' => [__($messageKey)]]);
        }

        return $notes;
    }
}
