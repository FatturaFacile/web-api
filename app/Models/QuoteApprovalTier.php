<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One level of the quote approval ladder of a company. A quote that breaches the limits of a level needs the approval
 * of that level's role and of every lower level.
 */
class QuoteApprovalTier extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'level',
        'name',
        'approver_role',
        'max_line_discount_percent',
        'max_global_discount_percent',
        'min_margin_percent',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'max_line_discount_percent' => 'decimal:2',
            'max_global_discount_percent' => 'decimal:2',
            'min_margin_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
