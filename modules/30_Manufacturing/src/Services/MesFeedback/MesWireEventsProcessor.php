<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesFeedback;

use Modules\Manufacturing\Exceptions\MesEventRejectedException;
use Modules\Manufacturing\Support\MesProblem;

/**
 * Applies a batch of outbox messages of the MES Core and answers in the shape its relay reads: one
 * `{id, status, message}` per message, with the status `applied`, `duplicate`, `stale` or `rejected`. Idempotency is the
 * one of the feedback processor (inbox by event id plus snapshot revision), so a redelivered batch changes nothing.
 */
final readonly class MesWireEventsProcessor
{
    public function __construct(private MesWireEventTranslator $translator, private MesFeedbackProcessor $processor) {}

    /**
     * Processes the message of the authenticated tenant.
     *
     * @param  array<string, mixed>  $wire
     * @return array{id: string|null, status: string, message: string|null}
     */
    public function __invoke(array $wire, string $tenantId): array
    {
        $id = isset($wire['id']) && is_string($wire['id']) ? $wire['id'] : null;

        try {
            $result = ($this->processor)(($this->translator)($wire, $tenantId), $tenantId);
        } catch (MesEventRejectedException $exception) {
            return ['id' => $id, 'status' => MesEventStatus::Rejected->value, 'message' => $exception->problemCode.': '.$exception->getMessage()];
        }

        /** @var array<string, mixed>|null $problem */
        $problem = $result['problem'] ?? null;

        return [
            'id' => $id,
            'status' => $result['status'],
            'message' => $problem === null ? null : $this->message($problem),
        ];
    }

    /**
     * A one-line description of a problem document, with the offending fields.
     *
     * @param  array<string, mixed>  $problem
     */
    private function message(array $problem): string
    {
        $message = (string) ($problem['code'] ?? MesProblem::document(422, 'event.invalid', '')['code']).': '.(string) ($problem['detail'] ?? $problem['title'] ?? 'rejected');

        foreach ((array) ($problem['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $message .= sprintf(' [%s %s]', (string) ($error['pointer'] ?? ''), (string) ($error['message'] ?? ''));
            }
        }

        return $message;
    }
}
