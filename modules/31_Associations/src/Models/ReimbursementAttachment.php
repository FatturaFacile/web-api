<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A receipt or another proof attached to a reimbursement, on the private disk.
 */
final class ReimbursementAttachment extends AssociationRecord
{
    protected $table = 'assoc_reimbursement_attachments';

    protected $hidden = ['disk', 'path'];
}
