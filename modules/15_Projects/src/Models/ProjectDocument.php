<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectDocument extends BaseEnterpriseModel
{
    protected $table = 'project_documents';

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'version' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
