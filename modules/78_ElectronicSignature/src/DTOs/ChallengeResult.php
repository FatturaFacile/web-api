<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\DTOs;

use DateTimeImmutable;

/**
 * What a recipient has to do next to prove who they are: nothing (the personal link is enough), type in a code that was
 * sent, approve a push notification on the phone, or continue on a page of the provider.
 */
final readonly class ChallengeResult
{
    public const string None = 'none';

    public const string Otp = 'otp';

    public const string Push = 'push';

    public const string Redirect = 'redirect';

    public function __construct(
        public string $type,
        public ?string $reference = null,
        public ?string $url = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $channel = null,
        public ?string $sandboxCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'url' => $this->url,
            'channel' => $this->channel,
            'sandbox_code' => $this->sandboxCode,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
        ];
    }
}
