<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A score of a customer: the history of these rows is the score history.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string $score
 * @property string|null $rating_band_id
 * @property string $rating
 * @property string $risk_level
 * @property array<int, array<string, mixed>> $breakdown
 * @property Carbon $scored_on
 * @property string|null $scored_by
 * @property string|null $notes
 */
final class CreditScore extends BaseEnterpriseModel
{
    protected $table = 'credit_scores';

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'breakdown' => 'array',
            'scored_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }
}
