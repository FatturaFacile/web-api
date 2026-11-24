<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Delivery;

final readonly class OutboundEmail
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $to,
        public string $fromEmail,
        public string $fromName,
        public string $subject,
        public string $html,
        public string $text,
        public array $headers = [],
        public ?string $replyTo = null,
    ) {}
}
