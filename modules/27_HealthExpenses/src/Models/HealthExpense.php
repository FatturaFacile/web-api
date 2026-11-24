<?php

declare(strict_types=1);

namespace Modules\HealthExpenses\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\HealthExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A document flagged as a health expense; the patient fiscal code is encrypted and hidden from API output.
 */
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

    /**
     * @var list<string>
     */
    protected $appends = ['transmission_status'];

    /**
     * Calculated grid columns (see App\Support\Http\GridColumns), in portable SQL; same rule as transmissionStatus().
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'transmission_status' => "CASE WHEN health_expenses.patient_opposition THEN 'opposed' ELSE 'pending' END",
        ];
    }

    /**
     * Transmission state shown by the list: an expense the patient opposes is never transmitted.
     *
     * @return Attribute<string, never>
     */
    protected function transmissionStatus(): Attribute
    {
        return Attribute::get(fn (): string => $this->patient_opposition ? 'opposed' : 'pending');
    }

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
