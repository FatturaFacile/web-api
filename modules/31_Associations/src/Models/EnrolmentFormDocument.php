<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\DocumentMode;

/**
 * A document of a form: required, optional or only offered for download.
 */
final class EnrolmentFormDocument extends AssociationRecord
{
    protected $table = 'assoc_enrolment_form_documents';

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['mode' => DocumentMode::class, 'sort_order' => 'integer'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(EnrolmentForm::class, 'form_id');
    }
}
