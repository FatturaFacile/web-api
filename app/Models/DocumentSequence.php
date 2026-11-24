<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'document_type',
        'fiscal_year',
        'prefix',
        'last_number',
        'padding',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'last_number' => 'integer',
            'padding' => 'integer',
        ];
    }
}
