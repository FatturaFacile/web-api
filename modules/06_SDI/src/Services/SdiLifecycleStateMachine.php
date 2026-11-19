<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Models\FpaDocument;

/**
 * Enforces the legal transitions of an electronic invoice envelope.
 */
final class SdiLifecycleStateMachine
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns written together with the new status
     *
     * @throws \DomainException when the transition is not allowed
     */
    public function transition(FpaDocument $document, FpaStatus $to, array $attributes = []): FpaDocument
    {
        $from = $document->status;

        if ($from === $to) {
            return $document;
        }

        if (! $from->canTransitionTo($to)) {
            throw new \DomainException("Transition {$from->value} -> {$to->value} is not allowed.");
        }

        $document->forceFill([...$attributes, 'status' => $to])->save();

        return $document;
    }
}
