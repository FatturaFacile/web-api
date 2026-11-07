<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk\Email;

use Modules\SDI\Contracts\ImapMailbox;
use Modules\SDI\Services\NativeImapMailbox;
use Modules\ServiceManagement\Models\SupportMailbox;

/**
 * Opens a support mailbox with the native IMAP client of the PEC channel. Bound in the container, so tests can hand
 * over a scripted connection instead.
 */
class SupportMailboxConnector
{
    public function open(SupportMailbox $mailbox): ImapMailbox
    {
        return NativeImapMailbox::connect(
            $mailbox->imap_host,
            $mailbox->imap_port ?: 993,
            (string) $mailbox->imap_username,
            (string) $mailbox->imap_password,
        );
    }
}
