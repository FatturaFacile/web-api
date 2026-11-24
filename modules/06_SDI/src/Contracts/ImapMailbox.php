<?php

declare(strict_types=1);

namespace Modules\SDI\Contracts;

interface ImapMailbox
{
    /**
     * UIDs of the INBOX messages newer than the given one, oldest first.
     *
     * @return list<int>
     */
    public function uidsAfter(int $uid): array;

    /**
     * Raw RFC 822 source of a message. The message is not marked as read.
     */
    public function fetch(int $uid): string;

    public function close(): void;
}
