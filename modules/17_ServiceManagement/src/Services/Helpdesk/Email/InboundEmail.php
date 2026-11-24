<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk\Email;

/**
 * An e-mail read from a support mailbox, reduced to what the helpdesk needs.
 */
final readonly class InboundEmail
{
    /**
     * @param  array<string, string>  $headers  lower-cased names, decoded values
     * @param  list<string>  $references  Message-IDs of the References header, oldest first
     * @param  list<array{name: string, content: string, mime: string}>  $attachments
     */
    public function __construct(
        public array $headers,
        public ?string $messageId,
        public ?string $inReplyTo,
        public array $references,
        public ?string $fromEmail,
        public ?string $fromName,
        public string $subject,
        public string $body,
        public array $attachments,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
