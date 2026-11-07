<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * State machine shared by the statuses of the sales documents. The enum lists in `transitions()` the statuses each
 * status may move to; staying in the same status is always allowed, so repeating an operation is harmless. Both the app
 * layer and the invoicing module ask the enum before writing a status, which keeps the rule in one place.
 */
trait DocumentStatusMachine
{
    /**
     * Statuses this status may move to, besides itself.
     *
     * @return list<self>
     */
    abstract public function transitions(): array;

    /**
     * Every value of the enum, in declaration order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === $target || in_array($target, $this->transitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }

    /**
     * Whether a document whose stored status is `$from` may move to `$target`. A status outside the vocabulary (a row
     * written before the vocabulary was unified and not remapped) never moves.
     */
    public static function allows(self|string|null $from, self $target): bool
    {
        $current = $from instanceof self ? $from : self::tryFrom((string) $from);

        return $current !== null && $current->canTransitionTo($target);
    }

    /**
     * @throws ValidationException when the move is not allowed
     */
    public static function assertTransition(self|string|null $from, self $target): void
    {
        if (! self::allows($from, $target)) {
            throw ValidationException::withMessages(['status' => __('sales.invalid_status_transition', [
                'from' => $from instanceof self ? $from->value : (string) $from,
                'to' => $target->value,
            ])]);
        }
    }
}
