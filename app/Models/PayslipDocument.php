<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayslipDocument extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const TYPES = ['payslip', 'cu', 'lohnausweis'];

    protected $fillable = [
        'tenant_id', 'user_id', 'document_type', 'period_year', 'period_month', 'title',
        'file_path', 'country', 'published_at', 'read_at', 'read_ip', 'read_device_id',
    ];

    protected $hidden = ['file_path', 'read_ip'];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'published_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
