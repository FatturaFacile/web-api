<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One message read from a support mailbox and what became of it: ticket created, reply threaded, or ignored and why.
 */
final class SupportMailboxMessage extends BaseEnterpriseModel
{
    public const string Created = 'created';

    public const string Threaded = 'threaded';

    public const string Duplicate = 'duplicate';

    public const string IgnoredAutoReply = 'ignored_auto_reply';

    public const string IgnoredLoop = 'ignored_loop';

    public const string Failed = 'failed';

    protected $table = 'esm_support_mailbox_messages';

    protected function casts(): array
    {
        return [
            'uid' => 'integer',
        ];
    }
}
