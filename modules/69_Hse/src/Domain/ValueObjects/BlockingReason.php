<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\ValueObjects;

use Modules\Hse\Domain\Enums\BlockingReasonCode;

/**
 * One requirement that stops a worker dispatch: what is missing, expired or restricted, and the item it is about.
 */
final readonly class BlockingReason
{
    public function __construct(
        public BlockingReasonCode $code,
        public string $subject,
        public ?string $expiresAt = null,
        public ?string $detail = null,
    ) {}

    /**
     * @return array{code: string, subject: string, expires_at: string|null, detail: string|null}
     */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'subject' => $this->subject, 'expires_at' => $this->expiresAt, 'detail' => $this->detail];
    }
}
