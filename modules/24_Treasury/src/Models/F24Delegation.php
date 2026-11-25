<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Modello F24 payment delegation: taxpayer data, payment date and the lines of its sections. Debit and credit totals
 * must balance (credit offsets never exceed the debits) before the telematic file can be generated.
 *
 * @property string|null $bank_account_id
 * @property string $taxpayer_fiscal_code
 * @property string $taxpayer_name
 * @property string|null $taxpayer_first_name
 * @property CarbonInterface|null $taxpayer_birth_date
 * @property string|null $taxpayer_birth_place
 * @property string|null $taxpayer_birth_province
 * @property string|null $taxpayer_gender
 * @property CarbonInterface $payment_date
 * @property string $status
 * @property string $total_debit
 * @property string $total_credit
 * @property string $total_payable
 * @property string|null $file_name
 * @property string|null $file_content
 * @property string|null $file_hash
 * @property CarbonInterface|null $validated_at
 * @property CarbonInterface|null $generated_at
 * @property string|null $created_by
 */
final class F24Delegation extends BaseEnterpriseModel
{
    public const DRAFT = 'draft';

    public const VALIDATED = 'validated';

    public const GENERATED = 'generated';

    public const PAID = 'paid';

    protected $table = 'f24_delegations';

    /**
     * @var list<string>
     */
    protected $hidden = ['file_content'];

    protected function casts(): array
    {
        return [
            'taxpayer_birth_date' => 'date',
            'payment_date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'validated_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<F24DelegationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(F24DelegationLine::class, 'f24_delegation_id')->orderBy('position');
    }
}
