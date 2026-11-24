<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\Tenant;
use Modules\SDI\Contracts\ImapMailbox;

class PecMailboxFactory
{
    public function open(Tenant $tenant): ImapMailbox
    {
        return NativeImapMailbox::connect(
            (string) $tenant->sdi_pec_imap_host,
            (int) ($tenant->sdi_pec_imap_port ?: 993),
            (string) $tenant->sdi_pec_username,
            (string) $tenant->sdi_pec_password,
        );
    }
}
