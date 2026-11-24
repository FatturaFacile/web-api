<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EnrolmentUpload extends AssociationRecord
{
    protected $table = 'assoc_enrolment_uploads';

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(EnrolmentApplication::class, 'application_id');
    }

    public function formDocument(): BelongsTo
    {
        return $this->belongsTo(EnrolmentFormDocument::class, 'form_document_id');
    }
}
