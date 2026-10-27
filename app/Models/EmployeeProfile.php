<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeProfile extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'employee_profiles';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'is_cross_border_commuter',
        'social_security_number',
        'swiss_cantonal_tax_rate',
        'inps_code',
        'job_title',
        'department',
        'hire_date',
        'termination_date',
        'base_salary',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'is_cross_border_commuter' => 'boolean',
            'swiss_cantonal_tax_rate' => 'decimal:2',
            'hire_date' => 'date',
            'termination_date' => 'date',
            'base_salary' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
