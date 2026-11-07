<?php

declare(strict_types=1);

namespace Modules\HealthExpenses\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\HealthExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(HealthExpenseFactory::class)]
class HealthExpense extends Model
{
    /** @use HasFactory<HealthExpenseFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'document_type', 'document_id', 'document_kind', 'document_number', 'issue_date', 'payment_date',
        'is_advance_payment', 'expense_type', 'expense_flag', 'amount', 'tax_rate', 'vat_nature', 'device_number',
        'patient_fiscal_code', 'patient_opposition', 'payment_traceable', 'operation',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['patient_fiscal_code'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'payment_date' => 'date',
            'is_advance_payment' => 'boolean',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'patient_fiscal_code' => 'encrypted',
            'patient_opposition' => 'boolean',
            'payment_traceable' => 'boolean',
        ];
    }
}
