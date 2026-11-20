<?php

declare(strict_types=1);

namespace Modules\Academy\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Modules\Academy\Commands\EvaluateMissionCompletionEventCommand;
use Modules\Academy\Domain\Events\AcademyDomainEvent;
use Throwable;

/**
 * Feeds every ERP domain event to the mission evaluation. The event bus dispatches each event twice (by name and by
 * object); only the dispatch by name is handled so a mission is evaluated once. A failure of the Academy never reaches
 * the business transaction that raised the event.
 */
final readonly class EvaluateMissionsOnDomainEvent
{
    public function __construct(private EvaluateMissionCompletionEventCommand $command) {}

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        if (! $event instanceof DomainEventInterface || $event instanceof AcademyDomainEvent || $eventName !== $event->getEventName()) {
            return;
        }

        try {
            $this->command->execute($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
