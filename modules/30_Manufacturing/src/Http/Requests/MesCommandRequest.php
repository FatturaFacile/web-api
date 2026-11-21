<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Carbon\CarbonImmutable;

/**
 * Base of the MES operator board commands: the idempotency key (the Idempotency-Key header or the body field), the
 * phase version the client last saw and the instant the operator acted, which an offline client sends when it replays.
 */
abstract class MesCommandRequest extends ManufacturingRequest
{
    /**
     * Takes the idempotency key from the header when the body has none.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /**
     * Rules every command shares, merged by the rules of each request.
     *
     * @return array<string, mixed>
     */
    protected function commandRules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'expected_version' => ['nullable', 'integer', 'min:0'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    /**
     * The idempotency key.
     */
    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }

    /**
     * The phase version the client saw, if any.
     */
    public function expectedVersion(): ?int
    {
        $version = $this->validated('expected_version');

        return $version !== null ? (int) $version : null;
    }

    /**
     * The instant the operator acted, if the client sent it.
     */
    public function occurredAt(): ?CarbonImmutable
    {
        $at = $this->validated('occurred_at');

        return $at !== null ? CarbonImmutable::parse((string) $at) : null;
    }
}
