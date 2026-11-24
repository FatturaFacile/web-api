<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A document sent with the notice of a meeting, kept on the private disk.
 */
final class MeetingAttachment extends AssociationRecord
{
    protected $table = 'assoc_meeting_attachments';

    protected $hidden = ['disk', 'path'];
}
