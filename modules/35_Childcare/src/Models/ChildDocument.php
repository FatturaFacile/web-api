<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * A document in the virtual folder of a child: requested, returned or signed by a guardian on the family portal.
 *
 * @property string $id
 * @property string $child_id
 * @property ?string $requirement_id
 * @property string $title
 * @property string $status
 * @property ?string $file_path
 * @property ?string $signed_name
 */
final class ChildDocument extends ChildcareRecord
{
    protected $table = 'cc_child_documents';

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['signed_name' => 'encrypted', 'signed_at' => 'immutable_datetime'];
    }
}
